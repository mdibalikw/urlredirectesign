<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MekariCallbackController extends Controller
{
    public function handleCallback(Request $request)
    {
        // 1. Ambil payload dari request Mekari
        $payload = $request->all();
        
        Log::info('Mekari Callback Received:', $payload);

        // 2. Extract Document ID (Mekari webhook biasanya mengirimkan ID dokumen, bukan URL langsung)
        $documentId = $payload['document_id'] ?? $payload['id'] ?? null;
        $documentName = $payload['document_name'] ?? 'signed_document.pdf';

        $mfilesObjectId = $request->query('mfiles_object_id');
        $mfilesType = $request->query('mfiles_type', 0);

        if (!$mfilesObjectId) {
            return response()->json(['error' => 'mfiles_object_id query parameter is required'], 400);
        }

        try {
            // 3. Download dokumen dari Mekari menggunakan Endpoint GET /documents/:id/download
            $mekariApiUrl = env('MEKARI_API_URL', 'https://api.mekari.com/v1');
            $mekariAuthToken = env('MEKARI_AUTH_TOKEN');

            $documentResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $mekariAuthToken,
                'Date' => gmdate('D, d M Y H:i:s \G\M\T')
            ])->get("{$mekariApiUrl}/documents/{$documentId}/download");
            
            if ($documentResponse->failed()) {
                throw new \Exception('Failed to download document from Mekari. Status: ' . $documentResponse->status());
            }

            $fileContents = $documentResponse->body();

            // 4. Update ke M-Files DMS
            $mfilesApiUrl = env('MFILES_API_URL');
            $mfilesUsername = env('MFILES_USERNAME');
            $mfilesPassword = env('MFILES_PASSWORD');
            $mfilesVault = env('MFILES_VAULT');

            // a. Dapatkan Authentication Token M-Files
            $authResponse = Http::post("{$mfilesApiUrl}/server/authenticationtokens", [
                'Username' => $mfilesUsername,
                'Password' => $mfilesPassword,
                'VaultGuid' => $mfilesVault
            ]);

            if ($authResponse->failed()) {
                throw new \Exception('M-Files authentication failed.');
            }

            $mfilesToken = $authResponse->json('Value');

            // b. Check Out Object di M-Files
            $checkoutResponse = Http::withHeaders([
                'X-Authentication' => $mfilesToken,
                'Content-Type' => 'application/json'
            ])->post("{$mfilesApiUrl}/objects/{$mfilesType}/{$mfilesObjectId}/latest/checkedout", [
                'Value' => true
            ]);

            if ($checkoutResponse->failed()) {
                throw new \Exception('Failed to check out object in M-Files: ' . $checkoutResponse->body());
            }

            $checkedOutObject = $checkoutResponse->json();
            $checkedOutVersion = $checkedOutObject['ObjVer']['Version'];

            // c. Dapatkan ID File lama untuk ditimpa
            $filesResponse = Http::withHeaders([
                'X-Authentication' => $mfilesToken
            ])->get("{$mfilesApiUrl}/objects/{$mfilesType}/{$mfilesObjectId}/{$checkedOutVersion}/files");

            $filesData = $filesResponse->json();
            if (empty($filesData)) {
                throw new \Exception('No files found in the M-Files object to replace.');
            }
            $fileId = $filesData[0]['ID'];

            // d. Timpa file content (Replace File)
            $replaceFileResponse = Http::withHeaders([
                'X-Authentication' => $mfilesToken,
                'Content-Type' => 'application/octet-stream',
            ])->send('PUT', "{$mfilesApiUrl}/objects/{$mfilesType}/{$mfilesObjectId}/{$checkedOutVersion}/files/{$fileId}/content", [
                'body' => $fileContents
            ]);

            if ($replaceFileResponse->failed()) {
                throw new \Exception('Failed to replace file content in M-Files: ' . $replaceFileResponse->body());
            }

            // e. Ubah State Object menjadi 214 (Signed)
            $stateUpdateResponse = Http::withHeaders([
                'X-Authentication' => $mfilesToken,
                'Content-Type' => 'application/json'
            ])->put("{$mfilesApiUrl}/objects/{$mfilesType}/{$mfilesObjectId}/{$checkedOutVersion}/properties/39", [
                'PropertyDef' => 39,
                'TypedValue' => [
                    'DataType' => 9, // Lookup
                    'Lookup' => [
                        'Item' => 214 // State 214 (Signed)
                    ]
                ]
            ]);

            if ($stateUpdateResponse->failed()) {
                Log::warning("Gagal mengubah state object M-Files ke 214: " . $stateUpdateResponse->body());
            }

            // f. Check In Object di M-Files
            $checkinResponse = Http::withHeaders([
                'X-Authentication' => $mfilesToken,
                'Content-Type' => 'application/json'
            ])->post("{$mfilesApiUrl}/objects/{$mfilesType}/{$mfilesObjectId}/latest/checkedout", [
                'Value' => false
            ]);

            if ($checkinResponse->failed()) {
                throw new \Exception('Failed to check in object in M-Files: ' . $checkinResponse->body());
            }

            // --- Kirim Notifikasi Email ---
            $recipientEmail = env('NOTIFICATION_EMAIL', 'admin@example.com');
            try {
                \Illuminate\Support\Facades\Mail::raw("Dokumen '{$documentName}' telah selesai ditandatangani melalui Mekari eSign dan berhasil disimpan ke M-Files.", function ($message) use ($recipientEmail, $documentName) {
                    $message->to($recipientEmail)
                            ->subject("Notifikasi M-Files: Dokumen {$documentName} Selesai");
                });
                Log::info("Email notifikasi berhasil dikirim ke: {$recipientEmail}");
            } catch (\Exception $e) {
                // Jangan gagalkan seluruh proses hanya karena email gagal
                Log::warning('Email Notification Failed: ' . $e->getMessage());
            }
            // ------------------------------

            return response()->json([
                'message' => 'Document successfully processed and uploaded to M-Files',
                'mfiles_data' => $checkinResponse->json()
            ]);

        } catch (\Exception $e) {
            Log::error('Mekari to M-Files Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
