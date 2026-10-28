<?php


use Aws\S3\S3Client;

// Determine which storage backend to use.
function storage_driver(): string
{
    $driver = config('STORAGE_DRIVER', 'local');

    if (!in_array($driver, ['local', 's3'], true)) {
        throw new RuntimeException('Unsupported storage driver.');
    }

    return $driver;
}

// Create an S3 client using AWS SDK and the EC2 IAM Role.
function storage_s3_client(): S3Client
{
    static $client = null;

    if ($client === null) {
        $client = new S3Client([
            'version' => 'latest',
            'region' => config('AWS_REGION', 'eu-central-1'),
        ]);
    }

    return $client;
}

// File storage for capsule attachments.
//
// This is the only file that reads or writes uploaded files. They are kept on the local
// disk, in UPLOAD_DIR. The rest of the app only uses the three functions
// storage_save(), storage_delete() and storage_stream().

// Absolute path of the upload folder. A relative UPLOAD_DIR is relative to the project root.
function upload_dir(): string
{
    $dir = config('UPLOAD_DIR', 'storage/uploads');
    // Absolute paths look like "/var/uploads" (Linux, macOS) or "C:\uploads" (Windows).
    if (!preg_match('#^([A-Za-z]:)?[\\\\/]#', $dir)) {
        $dir = ROOT_DIR . '/' . $dir;
    }

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return rtrim($dir, '/\\');
}

// Save an uploaded file ($_FILES['...'] entry) and return its stored name, e.g. "3f9a...e1.png".
// The name is random, so users cannot guess other people's file names.

function storage_save(array $uploadedFile, string $extension): string
{
    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

    if (storage_driver() === 's3') {
        $mimeTypes = [
	    'pdf' => 'application/pdf',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
  	];
	 $contentType = $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';

        storage_s3_client()->putObject([
            'Bucket' => config('S3_BUCKET'),
            'Key' => 'uploads/' . $storedName,
            'SourceFile' => $uploadedFile['tmp_name'],
	    'ContentType' => $contentType,
        ]);

        return $storedName;
    }

    // Local storage (default).
    if (!move_uploaded_file($uploadedFile['tmp_name'], upload_dir() . '/' . $storedName)) {
        throw new RuntimeException('Could not save the uploaded file.');
    }

    return $storedName;
}



function storage_delete(?string $storedName): void
{
    if ($storedName === null || $storedName === '') {
        return;
    }

    $storedName = basename($storedName);

    if (storage_driver() === 's3') {
        storage_s3_client()->deleteObject([
            'Bucket' => config('S3_BUCKET'),
            'Key' => 'uploads/' . $storedName,
        ]);

        return;
    }

    // Local storage.
    $path = upload_dir() . '/' . $storedName;

    if (is_file($path)) {
        unlink($path);
    }
}


// Send a stored file to the browser.
// $disposition: "inline" (show in the browser) or "attachment" (download).

function storage_stream(string $storedName, string $mime, string $downloadName, string $disposition): void
{
    $storedName = basename($storedName);

    // Keep only safe characters for the plain filename.
    $asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $downloadName);

    $contentDisposition = sprintf(
        '%s; filename="%s"; filename*=UTF-8\'\'%s',
        $disposition,
        str_replace(['"', '\\'], '_', $asciiName),
        rawurlencode($downloadName)
    );

    if (storage_driver() === 's3') {
        $client = storage_s3_client();

        $command = $client->getCommand('GetObject', [
            'Bucket' => config('S3_BUCKET'),
            'Key' => 'uploads/' . $storedName,
            'ResponseContentType' => $mime,
            'ResponseContentDisposition' => $contentDisposition,
        ]);

        // The link expires after 5 minutes.
        $request = $client->createPresignedRequest($command, '+5 minutes');

        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . (string) $request->getUri(), true, 302);
        exit;
    }

    // Local storage.
    $path = upload_dir() . '/' . $storedName;

    if (!is_file($path)) {
        abort(404);
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . $contentDisposition);
    header('X-Content-Type-Options: nosniff');

    readfile($path);
}
