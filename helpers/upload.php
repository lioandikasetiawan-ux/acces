<?php

/**

 * Helper Upload & Kompres Gambar

 * Mendukung JPG dan PNG (Otomatis convert ke JPG)

 */



function uploadImage($file, $targetDir, $prefix) {

    // 1. Cek Error Upload

    if ($file['error'] !== UPLOAD_ERR_OK) {

        return false;

    }



    // 2. Validasi Tipe File

    $info = getimagesize($file['tmp_name']);

    if ($info === false) {

        return false; // Bukan gambar

    }



    $mime = $info['mime'];

    $allowedMime = ['image/jpeg', 'image/png', 'image/jpg'];

    

    if (!in_array($mime, $allowedMime)) {

        return false; // Format tidak didukung

    }



    // 3. Buat Resource Gambar dari Sumber

    $sourceImage = null;

    switch ($mime) {

        case 'image/jpeg':

        case 'image/jpg':

            $sourceImage = imagecreatefromjpeg($file['tmp_name']);

            break;

        case 'image/png':

            $sourceImage = imagecreatefrompng($file['tmp_name']);

            break;

    }



    if (!$sourceImage) {

        return false; // Gagal memproses gambar

    }



    // 4. Proses Kompresi & Resize (Max Lebar 800px)

    $width = imagesx($sourceImage);

    $height = imagesy($sourceImage);

    $newWidth = 800;

    

    // Jika gambar kecil, jangan dibesarkan

    if ($width <= $newWidth) {

        $newWidth = $width;

        $newHeight = $height;

    } else {

        $newHeight = floor($height * ($newWidth / $width));

    }



    // Buat Canvas Baru (True Color)

    $virtualImage = imagecreatetruecolor($newWidth, $newHeight);



    // -- KHUSUS PNG: Handle Transparansi agar tidak hitam --

    if ($mime == 'image/png') {

        imagealphablending($virtualImage, false);

        imagesavealpha($virtualImage, true);

        $transparent = imagecolorallocatealpha($virtualImage, 255, 255, 255, 127);

        imagefilledrectangle($virtualImage, 0, 0, $newWidth, $newHeight, $transparent);

    } else {

        // Untuk JPG beri background putih

        $white = imagecolorallocate($virtualImage, 255, 255, 255);

        imagefilledrectangle($virtualImage, 0, 0, $newWidth, $newHeight, $white);

    }



    // Copy & Resize

    imagecopyresampled($virtualImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);



    // 5. Generate Nama File Unik

    $extension = 'jpg'; // Kita paksa simpan jadi JPG agar seragam

    $fileName = $prefix . '_' . uniqid() . '.' . $extension;

    $targetFile = $targetDir . $fileName;



    // Pastikan folder ada

    if (!file_exists($targetDir)) {

        mkdir($targetDir, 0777, true);

    }



    // 6. Simpan sebagai JPG (Kualitas 50%)

    if ($mime == 'image/png') {

        // Jika aslinya PNG, convert ke JPG (background putih) untuk menghemat size

        $bg = imagecreatetruecolor($newWidth, $newHeight);

        imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));

        imagealphablending($bg, true);

        imagecopy($bg, $virtualImage, 0, 0, 0, 0, $newWidth, $newHeight);

        imagejpeg($bg, $targetFile, 50);

        imagedestroy($bg);

    } else {

        imagejpeg($virtualImage, $targetFile, 50);

    }



    // Bersihkan Memori

    imagedestroy($virtualImage);

    imagedestroy($sourceImage);



    return $fileName;

}

?>