<?php

namespace App\Services\Media;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Secure uploads: the real content type is sniffed (the client-supplied mime and extension are
 * never trusted), size limits come from config, raster images are re-encoded (stripping EXIF and
 * any embedded payload) and responsive WebP variants are generated.
 */
class MediaService
{
    public function store(UploadedFile $file, ?int $userId, array $meta = []): Media
    {
        $info = $this->inspect($file);
        $dir = 'media/'.now()->format('Y/m');
        $name = Str::uuid()->toString();

        $media = new Media;
        $media->fill(Arr::only($meta, ['title', 'alt_ar', 'alt_en', 'category']));
        $media->forceFill([
            'disk' => 'public',
            'original_name' => mb_substr($this->cleanName($file->getClientOriginalName()), 0, 200),
            'mime' => $info['mime'],
            'extension' => $info['extension'],
            'kind' => $info['kind'],
            'uploaded_by' => $userId,
        ]);

        $this->write($media, $file, $info, $dir, $name);
        $media->save();

        return $media;
    }

    /** Replace the file of an existing item, keeping its id (and therefore every usage reference). */
    public function replace(Media $media, UploadedFile $file): Media
    {
        $info = $this->inspect($file);
        if ($info['kind'] !== $media->kind) {
            throw ValidationException::withMessages(['file' => ['The replacement must be of the same kind as the original.']]);
        }

        $old = array_filter([$media->path, ...array_values($media->variants ?? [])]);
        $dir = 'media/'.now()->format('Y/m');
        $media->forceFill(['mime' => $info['mime'], 'extension' => $info['extension'], 'original_name' => mb_substr($this->cleanName($file->getClientOriginalName()), 0, 200)]);
        $this->write($media, $file, $info, $dir, Str::uuid()->toString());
        $media->save();

        Storage::disk($media->disk)->delete($old);

        return $media;
    }

    public function delete(Media $media): void
    {
        $files = array_filter([$media->path, ...array_values($media->variants ?? [])]);
        $media->delete();
        Storage::disk($media->disk)->delete($files);
    }

    /** @return array{mime: string, extension: string, kind: string} */
    private function inspect(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => ['The upload failed.']]);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: '';
        $cfg = config('promofy.uploads');
        $kb = (int) ceil($file->getSize() / 1024);

        foreach ([['image', 'image_mimes', 'max_image_kb'], ['video', 'video_mimes', 'max_video_kb'], ['document', 'document_mimes', 'max_document_kb']] as [$kind, $mimes, $max]) {
            if (isset($cfg[$mimes][$mime])) {
                if ($kb > $cfg[$max]) {
                    throw ValidationException::withMessages(['file' => ["The file exceeds the {$cfg[$max]} KB limit for {$kind} uploads."]]);
                }
                // Office documents are zip containers: accept them only with a matching extension.
                $ext = strtolower($file->getClientOriginalExtension());
                if ($mime === 'application/zip' && $ext !== 'zip') {
                    break;
                }

                return ['mime' => $mime, 'extension' => $cfg[$mimes][$mime], 'kind' => $kind];
            }
        }

        // docx/xlsx/pptx are detected as zip by some libmagic builds.
        $ext = strtolower($file->getClientOriginalExtension());
        if ($mime === 'application/zip' && in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
            $officeMime = array_search($ext, $cfg['document_mimes'], true);
            if ((int) ceil($file->getSize() / 1024) <= $cfg['max_document_kb']) {
                return ['mime' => $officeMime, 'extension' => $ext, 'kind' => 'document'];
            }
        }

        throw ValidationException::withMessages(['file' => ['This file type is not allowed.']]);
    }

    /** @param  array{mime: string, extension: string, kind: string}  $info */
    private function write(Media $media, UploadedFile $file, array $info, string $dir, string $name): void
    {
        $disk = Storage::disk('public');
        $variants = [];

        if ($info['kind'] === 'image') {
            $source = $this->load($file->getRealPath(), $info['mime']);
            if ($source === null) {
                throw ValidationException::withMessages(['file' => ['The image could not be read.']]);
            }
            $width = imagesx($source);
            $height = imagesy($source);
            if ($width > 12000 || $height > 12000) {
                throw ValidationException::withMessages(['file' => ['The image dimensions are too large.']]);
            }

            $path = "{$dir}/{$name}.{$info['extension']}";
            $disk->put($path, $this->encode($source, $info['mime']));
            foreach (config('promofy.uploads.image_variants') as $variant => $max) {
                if ($width > $max) {
                    $scaled = imagescale($source, $max, (int) round($height * $max / $width), IMG_BICUBIC);
                    if ($scaled !== false) {
                        $variants[$variant] = "{$dir}/{$name}-{$variant}.webp";
                        $disk->put($variants[$variant], $this->encode($scaled, 'image/webp'));
                    }
                }
            }
            $media->forceFill(['width' => $width, 'height' => $height]);
        } else {
            $path = "{$dir}/{$name}.{$info['extension']}";
            $disk->putFileAs($dir, $file, "{$name}.{$info['extension']}");
        }

        $media->forceFill([
            'path' => $path,
            'size' => $disk->size($path),
            'variants' => $variants ?: null,
            'checksum' => hash('sha256', $disk->get($path)),
        ]);
    }

    private function load(string $path, string $mime): ?\GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };

        if ($image === false) {
            return null;
        }
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    private function encode(\GdImage $image, string $mime): string
    {
        ob_start();
        match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 85),
            'image/png' => imagepng($image, null, 6),
            default => imagewebp($image, null, 82),
        };

        return (string) ob_get_clean();
    }

    private function cleanName(string $name): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}\s._-]+/u', '', $name) ?? '') ?: 'file';
    }
}
