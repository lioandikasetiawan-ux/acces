<?php
/**
 * Render pagination links.
 * @param int $currentPage Current page number (1-based)
 * @param int $totalPages  Total number of pages
 * @param string $baseUrl  Base URL with existing query params (without page=)
 */
function renderPagination($currentPage, $totalPages, $baseUrl) {
    if ($totalPages <= 1) return;
    
    $separator = (strpos($baseUrl, '?') !== false) ? '&' : '?';
    
    echo '<div class="flex items-center justify-between px-4 py-3 bg-white border-t">';
    echo '<div class="text-sm text-gray-500">Halaman ' . $currentPage . ' dari ' . $totalPages . '</div>';
    echo '<div class="flex gap-1">';
    
    // Previous
    if ($currentPage > 1) {
        echo '<a href="' . $baseUrl . $separator . 'page=' . ($currentPage - 1) . '" class="px-3 py-1 text-sm border rounded hover:bg-gray-100">&laquo; Prev</a>';
    }
    
    // Page numbers
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    
    if ($start > 1) {
        echo '<a href="' . $baseUrl . $separator . 'page=1" class="px-3 py-1 text-sm border rounded hover:bg-gray-100">1</a>';
        if ($start > 2) echo '<span class="px-2 py-1 text-gray-400">...</span>';
    }
    
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $currentPage) {
            echo '<span class="px-3 py-1 text-sm border rounded bg-blue-600 text-white font-bold">' . $i . '</span>';
        } else {
            echo '<a href="' . $baseUrl . $separator . 'page=' . $i . '" class="px-3 py-1 text-sm border rounded hover:bg-gray-100">' . $i . '</a>';
        }
    }
    
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) echo '<span class="px-2 py-1 text-gray-400">...</span>';
        echo '<a href="' . $baseUrl . $separator . 'page=' . $totalPages . '" class="px-3 py-1 text-sm border rounded hover:bg-gray-100">' . $totalPages . '</a>';
    }
    
    // Next
    if ($currentPage < $totalPages) {
        echo '<a href="' . $baseUrl . $separator . 'page=' . ($currentPage + 1) . '" class="px-3 py-1 text-sm border rounded hover:bg-gray-100">Next &raquo;</a>';
    }
    
    echo '</div></div>';
}
