<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\MfilesDocument;

class CheckMFilesApprovalState extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mfiles:check-approval';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for documents in M-Files that are in Approval State (213) and save to DB';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Mulai mengecek dokumen di M-Files dengan State 213...');
        
        $mfilesApiUrl = env('MFILES_API_URL');
        $mfilesUsername = env('MFILES_USERNAME');
        $mfilesPassword = env('MFILES_PASSWORD');
        $mfilesVault = env('MFILES_VAULT');

        if (!$mfilesApiUrl) {
            $this->error('Konfigurasi M-Files API URL belum diset.');
            return;
        }

        try {
            // 1. Dapatkan Authentication Token M-Files
            $authResponse = Http::post("{$mfilesApiUrl}/server/authenticationtokens", [
                'Username' => $mfilesUsername,
                'Password' => $mfilesPassword,
                'VaultGuid' => $mfilesVault
            ]);

            if ($authResponse->failed()) {
                throw new \Exception('M-Files authentication failed.');
            }

            $mfilesToken = $authResponse->json('Value');

            // 2. Cari dokumen dengan State 213 (Property 39 = 213)
            $searchResponse = Http::withHeaders([
                'X-Authentication' => $mfilesToken
            ])->get("{$mfilesApiUrl}/objects?p39=213");

            if ($searchResponse->failed()) {
                throw new \Exception('Failed to search objects in M-Files. Status: ' . $searchResponse->status() . ' Body: ' . $searchResponse->body());
            }

            $data = $searchResponse->json();
            $items = $data['Items'] ?? [];

            $this->info('Ditemukan ' . count($items) . ' dokumen di state 213.');

            $newCount = 0;
            foreach ($items as $item) {
                $objectId = $item['ObjVer']['ID'] ?? null;
                $title = $item['Title'] ?? 'Unknown Document';

                if ($objectId) {
                    if (MfilesDocument::where('mfiles_object_id', $objectId)->exists()) {
                        continue;
                    }

                    $type = $item['ObjVer']['Type'] ?? 0;
                    $version = $item['ObjVer']['Version'] ?? 1;

                    // Fetch properties dari M-Files
                    $propertiesResponse = Http::withHeaders([
                        'X-Authentication' => $mfilesToken
                    ])->get("{$mfilesApiUrl}/objects/{$type}/{$objectId}/{$version}/properties");
                    
                    $signer1 = null;
                    $email1 = null;
                    $signer2 = null;
                    $email2 = null;
                    
                    if ($propertiesResponse->successful()) {
                        $properties = $propertiesResponse->json();
                        
                        $propSigner1NameId = env('MFILES_PROP_SIGNER1_NAME');
                        $propSigner1EmailId = env('MFILES_PROP_SIGNER1_EMAIL');
                        $propSigner2NameId = env('MFILES_PROP_SIGNER2_NAME');
                        $propSigner2EmailId = env('MFILES_PROP_SIGNER2_EMAIL');
                        
                        foreach ($properties as $prop) {
                            $def = $prop['PropertyDef'];
                            $val = $prop['TypedValue']['DisplayValue'] ?? ($prop['TypedValue']['Value'] ?? null);
                            
                            if ($propSigner1NameId !== null && $def == $propSigner1NameId) $signer1 = $val;
                            if ($propSigner1EmailId !== null && $def == $propSigner1EmailId) $email1 = $val;
                            if ($propSigner2NameId !== null && $def == $propSigner2NameId) $signer2 = $val;
                            if ($propSigner2EmailId !== null && $def == $propSigner2EmailId) $email2 = $val;
                        }
                    }

                    $doc = MfilesDocument::firstOrCreate(
                        ['mfiles_object_id' => $objectId],
                        [
                            'title' => $title,
                            'state_id' => 213,
                            'send_notification' => 0,
                            'signer1' => $signer1,
                            'email1' => $email1,
                            'signer2' => $signer2,
                            'email2' => $email2,
                        ]
                    );

                    if ($doc->wasRecentlyCreated) {
                        $newCount++;
                        $this->info("Dokumen ditambahkan ke DB: {$title} (ID: {$objectId})");

                        try {

                            if (isset($item['Files']) && count($item['Files']) > 0) {
                                $fileId = $item['Files'][0]['ID'];
                                $fileName = $item['Files'][0]['Name'] . '.' . $item['Files'][0]['Extension'];

                                // 3. Download file content dari M-Files
                                $this->info("Mengunduh dokumen dari M-Files: {$fileName}");
                                $fileResponse = Http::withHeaders([
                                    'X-Authentication' => $mfilesToken
                                ])->get("{$mfilesApiUrl}/objects/{$type}/{$objectId}/{$version}/files/{$fileId}/content");

                                if ($fileResponse->failed()) {
                                    throw new \Exception("Gagal mengunduh file {$fileName} dari M-Files.");
                                }

                                $fileContent = $fileResponse->body();
                                $base64Content = base64_encode($fileContent);

                                // 4. Upload ke Mekari eSign
                                $this->info("Mengunggah dokumen ke Mekari eSign: {$fileName}");
                                $mekariApiUrl = env('MEKARI_API_URL', 'https://api.mekari.com/v1');
                                $mekariAuthToken = env('MEKARI_AUTH_TOKEN');
                                $mekariPage = (int) env('MEKARI_SIGNATURE_POSITION_PAGE', 1);

                                $callbackUrl = env('APP_URL') . '/api/mekari/callback?mfiles_object_id=' . $objectId . '&mfiles_type=' . $type;

                                $signers = [];
                                
                                // Tambahkan Signer 1 jika email ada
                                if ($email1) {
                                    $signers[] = [
                                        'name' => $signer1 ?: 'Signer 1',
                                        'email' => $email1,
                                        'annotations' => [
                                            [
                                                'type_of' => 'signature',
                                                'page' => $mekariPage,
                                                'position_x' => 210,
                                                'position_y' => 873,
                                                'element_width' => 120,
                                                'element_height' => 100,
                                                'canvas_width' => 794,
                                                'canvas_height' => 1123
                                            ]
                                        ]
                                    ];
                                }
                                
                                // Tambahkan Signer 2 jika email ada
                                if ($email2) {
                                    $signers[] = [
                                        'name' => $signer2 ?: 'Signer 2',
                                        'email' => $email2,
                                        'annotations' => [
                                            [
                                                'type_of' => 'signature',
                                                'page' => $mekariPage,
                                                'position_x' => 533,
                                                'position_y' => 874,
                                                'element_width' => 120,
                                                'element_height' => 100,
                                                'canvas_width' => 794,
                                                'canvas_height' => 1123
                                            ]
                                        ]
                                    ];
                                }

                                if (empty($signers)) {
                                    throw new \Exception("Tidak ada data signer yang ditemukan dari M-Files (pastikan Property Def ID di .env sudah benar dan diisi).");
                                }

                                $mekariPayload = [
                                    'doc' => $base64Content,
                                    'filename' => $fileName,
                                    'callback_url' => $callbackUrl,
                                    'signers' => $signers
                                ];

                                $uploadResponse = Http::withHeaders([
                                    'Authorization' => 'Bearer ' . $mekariAuthToken,
                                    'Content-Type' => 'application/json'
                                ])->post("{$mekariApiUrl}/documents/request_global_sign_only", $mekariPayload);

                                if ($uploadResponse->failed()) {
                                    throw new \Exception("Gagal mengunggah dokumen ke Mekari. Status: " . $uploadResponse->status() . " Body: " . $uploadResponse->body());
                                }

                                $this->info("Dokumen berhasil diunggah ke Mekari.");
                            } else {
                                $this->warn("Dokumen {$title} tidak memiliki file yang dilampirkan.");
                            }
                        } catch (\Exception $e) {
                            $this->error('Error Upload Mekari: ' . $e->getMessage());
                            Log::error('Mekari Upload Error: ' . $e->getMessage());
                        }
                    }
                }
            }

            $this->info("Proses selesai. Ada {$newCount} dokumen baru yang di-insert.");

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            Log::error('CheckMFilesApprovalState Error: ' . $e->getMessage());
        }
    }
}
