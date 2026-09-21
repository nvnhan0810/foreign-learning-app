<?php

namespace Flc\Media\Infrastructure\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStorageService
{
    public function storeAudio(UploadedFile $file, int $userId): array
    {
        $extension = $file->getClientOriginalExtension() ?: 'mp3';
        $filename = Str::uuid().'.'.$extension;
        $path = "media/{$userId}/{$filename}";

        $disk = config('filesystems.default', 'local');
        Storage::disk($disk)->putFileAs(
            "media/{$userId}",
            $file,
            $filename
        );

        return [
            'path' => $path,
            'disk' => $disk,
            'original_name' => $file->getClientOriginalName(),
        ];
    }

    public function absolutePath(string $disk, string $path): string
    {
        return Storage::disk($disk)->path($path);
    }

    public function deleteAudio(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }
}
