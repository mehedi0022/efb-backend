<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeneralSetting;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class GeneralSettingController extends Controller
{
    public function index(Request $request)
    {
        $settings = GeneralSetting::orderByDesc('id')->get();

        return response()->json([
            'success' => true,
            'data' => $settings,
        ]);
    }

    public function store(Request $request)
    {
        $existingSetting = GeneralSetting::query()->orderByDesc('id')->first();
        if ($existingSetting) {
            return $this->update($request, $existingSetting->id);
        }

        $validated = $request->validate(
            $this->rules(false),
            $this->messages()
        );

        try {
            $uploadDirectory = $this->ensureUploadDirectory();
            $columns = $this->tableColumns();

            $data = $this->preparePersistableData($validated);
            $logoPath = $this->storeUploadedImage($request->file('logo'), $uploadDirectory);
            $faviconPath = $this->storeUploadedImage($request->file('favicon'), $uploadDirectory);
            $latestSetting = GeneralSetting::query()->orderByDesc('id')->first();

            if (!isset($columns['logo']) || !isset($columns['favicon'])) {
                throw new \RuntimeException('Settings table is missing logo/favicon columns. Run migrations first.');
            }

            if (
                !array_key_exists('name', $data)
                || trim((string) ($data['name'] ?? '')) === ''
            ) {
                $data['name'] = trim((string) ($latestSetting?->name ?? 'General Setting'));
            }

            $data['logo'] = $logoPath ?? $latestSetting?->logo;
            $data['favicon'] = $faviconPath ?? ($latestSetting?->favicon ?? '');
            $data['status'] = array_key_exists('status', $data) ? (int) $data['status'] : 1;

            $setting = GeneralSetting::create($data);

            return response()->json(['success' => true, 'data' => $setting], 201);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save settings. Please try again.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate(
            $this->rules(true),
            $this->messages()
        );

        $setting = GeneralSetting::findOrFail($id);

        try {
            $uploadDirectory = $this->ensureUploadDirectory();
            $columns = $this->tableColumns();

            if (!isset($columns['logo']) || !isset($columns['favicon'])) {
                throw new \RuntimeException('Settings table is missing logo/favicon columns. Run migrations first.');
            }

            $data = $this->preparePersistableData($validated);

            if (
                !array_key_exists('name', $data)
                || trim((string) ($data['name'] ?? '')) === ''
            ) {
                $data['name'] = $setting->name;
            }

            if ($request->hasFile('logo')) {
                $newLogoPath = $this->storeUploadedImage($request->file('logo'), $uploadDirectory);
                $data['logo'] = $newLogoPath;
                $this->deleteUploadedFile($setting->logo);
            } else {
                $data['logo'] = $setting->logo;
            }

            if ($request->hasFile('favicon')) {
                $newFaviconPath = $this->storeUploadedImage($request->file('favicon'), $uploadDirectory);
                $data['favicon'] = $newFaviconPath;
                $this->deleteUploadedFile($setting->favicon);
            } else {
                $data['favicon'] = $setting->favicon;
            }

            if (!array_key_exists('status', $data)) {
                $data['status'] = $setting->status ?? 1;
            }

            $setting->update($data);

            return response()->json(['success' => true, 'data' => $setting]);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update settings. Please try again.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function updateStatus(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'status' => 'required|integer',
        ]);

        GeneralSetting::whereIn('id', $request->ids)->update(['status' => $request->status]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
        ]);

        $settings = GeneralSetting::whereIn('id', $request->ids)->get();
        foreach ($settings as $setting) {
            $this->deleteUploadedFile($setting->logo ?? null);
            $this->deleteUploadedFile($setting->favicon ?? null);

            $setting->delete();
        }

        return response()->json(['success' => true]);
    }

    private function rules(bool $isUpdate = false): array
    {
        return [
            'name' => 'nullable|string|max:55',
            'copyright' => 'nullable|string|max:155',
            'logo' => [
                'nullable',
                'file',
                'max:500',
                $this->anyImageRule('Logo'),
            ],
            'favicon' => [
                'nullable',
                'file',
                'max:500',
                $this->anyImageRule('Favicon'),
            ],
            'status' => 'nullable|integer',
            'description' => 'nullable|string',
            'header_bg_color' => 'nullable|string',
            'footer_bg_color' => 'nullable|string',
            'button_primary_color' => 'nullable|string',
            'button_secondary_color' => 'nullable|string',
            'courier_charge' => 'nullable|integer|min:0',
            'fb_link' => 'nullable|string',
            'hotline' => 'nullable|string|max:50',
            'whatsapp' => 'nullable|string|max:50',
            'messenger' => 'nullable|string|max:255',
            'footer_payment_enabled' => 'nullable|in:0,1',
        ];
    }

    private function messages(): array
    {
        return [
            'logo.max' => 'Logo size must be less than or equal to 500KB.',
            'favicon.max' => 'Favicon size must be less than or equal to 500KB.',
        ];
    }

    private function anyImageRule(string $label): \Closure
    {
        return function ($attribute, $value, $fail) use ($label): void {
            if (!$value instanceof UploadedFile) {
                return;
            }

            $mime = strtolower((string) $value->getMimeType());
            $extension = strtolower((string) $value->getClientOriginalExtension());

            $knownImageExtensions = [
                'jpg',
                'jpeg',
                'png',
                'gif',
                'bmp',
                'webp',
                'svg',
                'ico',
                'avif',
                'tif',
                'tiff',
                'heic',
                'heif',
                'jfif',
            ];

            $isMimeImage = $mime !== '' && str_starts_with($mime, 'image/');
            $isKnownImageExtension = $extension !== '' && in_array($extension, $knownImageExtensions, true);

            if (!$isMimeImage && !$isKnownImageExtension) {
                $fail($label . ' must be an image file.');
            }
        };
    }

    private function ensureUploadDirectory(): string
    {
        $absolutePath = public_path('uploads/settings');

        if (!File::exists($absolutePath)) {
            File::makeDirectory($absolutePath, 0755, true);
        }

        return $absolutePath;
    }

    private function storeUploadedImage(?UploadedFile $image, string $uploadDirectory): ?string
    {
        if (!$image) {
            return null;
        }

        $originalName = pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = strtolower((string) ($image->getClientOriginalExtension() ?: $image->guessExtension() ?: 'img'));
        $safeName = Str::slug($originalName ?: 'file');
        $fileName = time() . '-' . ($safeName ?: 'file') . '-' . Str::random(6) . '.' . $extension;

        $image->move($uploadDirectory, $fileName);

        return 'uploads/settings/' . $fileName;
    }

    private function deleteUploadedFile(?string $path): void
    {
        if (!$path) {
            return;
        }

        $absolutePath = public_path(ltrim($path, '/'));
        if (File::exists($absolutePath)) {
            File::delete($absolutePath);
        }
    }

    private function preparePersistableData(array $validated): array
    {
        $columns = $this->tableColumns();
        $allowedFields = [
            'name',
            'copyright',
            'description',
            'header_bg_color',
            'footer_bg_color',
            'button_primary_color',
            'button_secondary_color',
            'courier_charge',
            'fb_link',
            'hotline',
            'whatsapp',
            'messenger',
            'footer_payment_enabled',
            'status',
        ];

        $data = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $validated) && isset($columns[$field])) {
                if ($field === 'courier_charge') {
                    $data[$field] = max(0, (int) $validated[$field]);
                    continue;
                }
                if ($field === 'footer_payment_enabled') {
                    $data[$field] = (int) $validated[$field] === 1 ? 1 : 0;
                    continue;
                }
                $data[$field] = $validated[$field];
            }
        }

        return $data;
    }

    private function tableColumns(): array
    {
        $table = (new GeneralSetting())->getTable();
        return array_flip(Schema::getColumnListing($table));
    }
}
