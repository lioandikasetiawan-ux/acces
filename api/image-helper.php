<?php
/**
 * IMAGE COMPRESSION HELPER
 * Fungsi untuk compress dan resize gambar sebelum disimpan
 * Mengurangi ukuran file untuk performa aplikasi lebih cepat
 */

/**
 * Compress dan resize gambar
 * 
 * @param string $source_path - Path file gambar asli
 * @param int $max_width - Lebar maksimal (default: 1024px)
 * @param int $max_height - Tinggi maksimal (default: 1024px)
 * @param int $quality - Kualitas kompresi 0-100 (default: 75)
 * @return string|false - Base64 encoded image atau false jika gagal
 */
function compressImage($source_path, $max_width = 1024, $max_height = 1024, $quality = 75) {
    // Cek apakah file ada
    if (!file_exists($source_path)) {
        return false;
    }

    // Dapatkan informasi gambar
    $image_info = getimagesize($source_path);
    if ($image_info === false) {
        return false;
    }

    list($orig_width, $orig_height, $image_type) = $image_info;
    $mime_type = $image_info['mime'];

    // Load gambar berdasarkan tipe
    switch ($image_type) {
        case IMAGETYPE_JPEG:
            $source_image = imagecreatefromjpeg($source_path);
            break;
        case IMAGETYPE_PNG:
            $source_image = imagecreatefrompng($source_path);
            break;
        case IMAGETYPE_GIF:
            $source_image = imagecreatefromgif($source_path);
            break;
        default:
            return false;
    }

    if ($source_image === false) {
        return false;
    }

    // Hitung dimensi baru dengan mempertahankan aspect ratio
    $ratio = min($max_width / $orig_width, $max_height / $orig_height);
    
    // Jika gambar sudah lebih kecil dari max, gunakan ukuran asli
    if ($ratio >= 1) {
        $new_width = $orig_width;
        $new_height = $orig_height;
    } else {
        $new_width = round($orig_width * $ratio);
        $new_height = round($orig_height * $ratio);
    }

    // Buat gambar baru dengan dimensi yang sudah di-resize
    $new_image = imagecreatetruecolor($new_width, $new_height);

    // Preserve transparency untuk PNG
    if ($image_type == IMAGETYPE_PNG) {
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
        $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
        imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);
    }

    // Resize gambar
    imagecopyresampled(
        $new_image, 
        $source_image, 
        0, 0, 0, 0, 
        $new_width, $new_height, 
        $orig_width, $orig_height
    );

    // Simpan ke buffer untuk konversi base64
    ob_start();
    
    switch ($image_type) {
        case IMAGETYPE_JPEG:
            imagejpeg($new_image, null, $quality);
            $output_mime = 'image/jpeg';
            break;
        case IMAGETYPE_PNG:
            // PNG quality: 0 (no compression) to 9 (max compression)
            $png_quality = round((100 - $quality) / 11.11);
            imagepng($new_image, null, $png_quality);
            $output_mime = 'image/png';
            break;
        case IMAGETYPE_GIF:
            imagegif($new_image);
            $output_mime = 'image/gif';
            break;
        default:
            imagejpeg($new_image, null, $quality);
            $output_mime = 'image/jpeg';
    }
    
    $image_data = ob_get_clean();

    // Bersihkan memory
    imagedestroy($source_image);
    imagedestroy($new_image);

    // Return base64 encoded image
    return 'data:' . $output_mime . ';base64,' . base64_encode($image_data);
}

/**
 * Compress gambar dengan preset untuk foto kejadian/absensi
 * Optimized untuk mobile upload
 * 
 * @param string $source_path - Path file gambar asli
 * @return string|false - Base64 encoded image atau false jika gagal
 */
function compressPhotoForMobile($source_path) {
    // Preset untuk mobile: max 800px, quality 70%
    // Menghasilkan file yang jauh lebih kecil tapi tetap bagus untuk bukti
    return compressImage($source_path, 800, 800, 70);
}

/**
 * Compress gambar dengan preset untuk foto profil
 * 
 * @param string $source_path - Path file gambar asli
 * @return string|false - Base64 encoded image atau false jika gagal
 */
function compressPhotoProfile($source_path) {
    // Preset untuk profile: max 400px, quality 80%
    return compressImage($source_path, 400, 400, 80);
}
?>
