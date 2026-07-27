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

        if (!$documentId) {
            return response()->json(['error' => 'Document ID not found in payload'], 400);
        }

        try {
            // 3. Download dokumen dari Mekari menggunakan Endpoint GET /documents/:id/download
            $mekariApiUrl = env('MEKARI_API_URL', 'https://api.mekari.com/v1'); // Sesuaikan dengan base URL Mekari API
            $mekariAuthToken = env('MEKARI_AUTH_TOKEN'); // Token untuk mengakses API Mekari

            $documentResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $mekariAuthToken,
                'Date' => gmdate('D, d M Y H:i:s \G\M\T')
            ])->get("{$mekariApiUrl}/documents/{$documentId}/download");
            
            if ($documentResponse->failed()) {
                throw new \Exception('Failed to download document from Mekari. Status: ' . $documentResponse->status());
            }

            $fileContents = $documentResponse->body();

            // 3. Upload ke M-Files DMS
            $mfilesApiUrl = env('MFILES_API_URL');
            $mfilesUsername = env('MFILES_USERNAME');
            $mfilesPassword = env('MFILES_PASSWORD');
            $mfilesVault = env('MFILES_VAULT');

            // Ini adalah contoh logic autentikasi & upload ke M-Files.
            // Implementasi nyata membutuhkan struktur request yang sesuai dengan M-Files Web Service (REST API)

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

            // b. Upload file ke M-Files temporary upload endpoint
            $uploadResponse = Http::withHeaders([
                'X-Authentication' => $mfilesToken,
                'Content-Type' => 'application/octet-stream',
            ])->send('POST', "{$mfilesApiUrl}/files", [
                'body' => $fileContents
            ]);

            if ($uploadResponse->failed()) {
                throw new \Exception('Failed to upload temporary file to M-Files.');
            }

            $uploadData = $uploadResponse->json();
            $uploadId = $uploadData['UploadID'] ?? $uploadData[0]['UploadID'];

            $mfilesClassId = (int) env('MFILES_CLASS_ID', 2);
            $mfilesWorkflowId = (int) env('MFILES_WORKFLOW_ID', 101);
            $mfilesStateId = env('MFILES_STATE_ID') ? (int) env('MFILES_STATE_ID') : null;

            $propertyValues = [
                [
                    'PropertyDef' => 0, // Name or Title
                    'TypedValue' => [
                        'DataType' => 1, // Text
                        'Value' => $documentName
                    ]
                ],
                [
                    'PropertyDef' => 100, // Class
                    'TypedValue' => [
                        'DataType' => 9, // Lookup
                        'Lookup' => [
                            'Item' => $mfilesClassId
                        ]
                    ]
                ],
                [
                    'PropertyDef' => 38, // Workflow
                    'TypedValue' => [
                        'DataType' => 9, // Lookup
                        'Lookup' => [
                            'Item' => $mfilesWorkflowId
                        ]
                    ]
                ]
            ];

            if ($mfilesStateId) {
                $propertyValues[] = [
                    'PropertyDef' => 39, // State
                    'TypedValue' => [
                        'DataType' => 9, // Lookup
                        'Lookup' => [
                            'Item' => $mfilesStateId
                        ]
                    ]
                ];
            }

            // c. Buat Object/Document di M-Files menggunakan UploadID tersebut
            // Sesuai postman collection, tambahkan ?checkIn=true agar file otomatis ter-check-in di M-Files
            $createObjectResponse = Http::withHeaders([
                'X-Authentication' => $mfilesToken,
                'Content-Type' => 'application/json',
            ])->post("{$mfilesApiUrl}/objects/0?checkIn=true", [
                'PropertyValues' => $propertyValues,
                'Files' => [
                    [
                        'UploadID' => $uploadId,
                        'Title' => $documentName,
                        'Extension' => 'pdf'
                    ]
                ]
            ]);

            if ($createObjectResponse->failed()) {
                throw new \Exception('Failed to create document object in M-Files.');
            }

            return response()->json([
                'message' => 'Document successfully processed and uploaded to M-Files',
                'mfiles_data' => $createObjectResponse->json()
            ]);

        } catch (\Exception $e) {
            Log::error('Mekari to M-Files Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
