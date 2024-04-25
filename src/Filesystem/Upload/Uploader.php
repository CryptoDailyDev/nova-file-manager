<?php

declare(strict_types=1);

namespace Oneduo\NovaFileManager\Filesystem\Upload;

use Intervention\Image\ImageManagerStatic as Image;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Oneduo\NovaFileManager\Contracts\Filesystem\Upload\Uploader as UploaderContract;
use Oneduo\NovaFileManager\Events\FileUploaded;
use Oneduo\NovaFileManager\Events\FileUploading;
use Oneduo\NovaFileManager\Http\Requests\UploadFileRequest;
use Pion\Laravel\ChunkUpload\Exceptions\UploadMissingFileException;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;

class Uploader implements UploaderContract
{
    /**
     * @throws \Pion\Laravel\ChunkUpload\Exceptions\UploadMissingFileException
     * @throws \Pion\Laravel\ChunkUpload\Exceptions\UploadFailedException
     */
    public function handle(UploadFileRequest $request, string $index = 'file'): array
    {
        if (!$request->validateUpload()) {
            throw ValidationException::withMessages([
                'file' => [__('nova-file-manager::errors.file.upload_validation')],
            ]);
        }

        $receiver = new FileReceiver($index, $request, HandlerFactory::classFromRequest($request));

        if ($receiver->isUploaded() === false) {
            throw new UploadMissingFileException;
        }

        $save = $receiver->receive();

        if ($save->isFinished()) {
                try {
                    $fileExtension = $save->getFile()->getClientOriginalExtension();
                    $currentFilePath = $save->getFile()->getRealPath();

                    $tempFileName = uniqid() . '.' . $fileExtension;
                    $tempFilePath = sys_get_temp_dir() . '/' . $tempFileName;

                    file_put_contents($tempFilePath, file_get_contents($currentFilePath));

                    $mime = mime_content_type($tempFilePath);
                    $info = pathinfo($tempFilePath);
                    $name = $info['basename'];
                    $output = new \CURLFile($tempFilePath, $mime, $name);
                    $data = array(
                        "files" => $output,
                    );

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, 'http://api.resmush.it/?qlty=80');
                    curl_setopt($ch, CURLOPT_POST,1);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                    $result = curl_exec($ch);
                    if (curl_errno($ch)) {
                        $result = curl_error($ch);
                    }
                    curl_close ($ch);

                    Storage::delete($tempFilePath);

                    $data = json_decode($result, true);

                    if (isset($data['dest'])) {
                        file_put_contents($currentFilePath, file_get_contents($data['dest']));
                    }
                } catch (\Exception $e) {
                }

            return $this->saveFile($request, $save->getFile());
        }

        $handler = $save->handler();

        return [
            'done' => $handler->getPercentageDone(),
            'status' => true,
        ];
    }

    public function saveFile(UploadFileRequest $request, UploadedFile $file): array
    {
        if (!$request->validateUpload($file, true)) {
            throw ValidationException::withMessages([
                'file' => [__('nova-file-manager::errors.file.upload_validation')],
            ]);
        }

        $folderPath = dirname($request->filePath());
        $filePath = $file->getClientOriginalName();
        $testPath = ltrim(str_replace('//', '/', "{$folderPath}/{$filePath}"), '/');

        event(new FileUploading($request->manager()->filesystem(), $request->manager()->getDisk(), $testPath));

        $path = $request->manager()->filesystem()->putFileAs(
            path: $folderPath,
            file: $file,
            name: $filePath,
        );

        event(new FileUploaded($request->manager()->filesystem(), $request->manager()->getDisk(), $path));

        return [
            'message' => __('nova-file-manager::messages.file.upload'),
        ];
    }
}
