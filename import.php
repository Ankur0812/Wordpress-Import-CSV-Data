<?php
/**
 * ARTIST IMPORT SCRIPT — Comma Separated CSV FIXED
 * Run once: /?import_artists=1
 */
add_action('init', function () {

    if (!isset($_GET['import_artists'])) return;

    $csv_file  = get_template_directory() . '/local_import_new.csv';
    $image_dir = get_template_directory() . '/artist-images/';
    
    if (!file_exists($csv_file)) wp_die("CSV not found: $csv_file");

    $handle = fopen($csv_file, 'r');
    if (!$handle) wp_die("Unable to open CSV.");

    // ------------------------------
    // READ TAB-DELIMITED HEADER
    // ------------------------------
    $raw_header = fgetcsv($handle, 0, ",");
    $header = [];

    foreach ($raw_header as $key) {
        // Normalize header keys
        $clean = strtolower(trim($key));
        $header[] = $clean;
    }

    $count = 0;

    while (($row = fgetcsv($handle, 0, ",")) !== false) {

        $count++;

        // Normalize row → associative array
        $data = [];
        foreach ($row as $i => $value) {
            $key = $header[$i];
            $data[$key] = trim($value);
        }
        // --------------------------------
        // BASIC FIELDS
        // --------------------------------
        $first = $data['first_name'] ?? '';
        $last  = $data['last_name'] ?? '';

        $title = trim("$first $last");
        if ($title === '') continue;

        $slug = basename(trim($data['bio_permalink'] ?? '', '/'));
        if ($slug === '') $slug = sanitize_title($title);

        $bio = $data['complete_bio'] ?? '';
        $bio = csv_text_to_blocks($bio);

        // --------------------------------
        // INSERT / UPDATE POST
        // --------------------------------
        $existing = get_page_by_path($slug, OBJECT, 'artists');

        $post_args = [
            'post_type'   => 'artists',
            'post_title'  => $title,
            'post_name'   => $slug,
            'post_content'=> $bio,
            'post_status' => 'publish'
        ];

        if ($existing) {
            $post_args['ID'] = $existing->ID;
            $post_id = wp_update_post($post_args);
        } else {
            $post_id = wp_insert_post($post_args);
        }

        if (is_wp_error($post_id)) continue;

        echo "<br>Imported: $title";

        // --------------------------------
        // SAVE ACF FIELDS
        // --------------------------------
        update_post_meta($post_id, 'first_name', $first);
        update_post_meta($post_id, 'last_name',  $last);

        update_post_meta($post_id, 'instagram_url', $data['instagram_url'] ?? '');
        update_post_meta($post_id, 'facebook_url',  $data['facebook_url'] ?? '');
        update_post_meta($post_id, 'twitter_url',   $data['x_url'] ?? '');
        update_post_meta($post_id, 'youtube_url',   $data['youtube_url'] ?? '');
        update_post_meta($post_id, 'three440_url',  $data['three440_url'] ?? '');

        // --------------------------------
        // GENRE
        // --------------------------------
        if (!empty($data['genre'])) {
            $genres = array_filter(array_map('trim', explode(',', $data['genre'])));
            foreach ($genres as $term) {
                if (!term_exists($term, 'genre')) wp_insert_term($term, 'genre');
            }
            wp_set_object_terms($post_id, $genres, 'genre', false);
        }

        // --------------------------------
        // INSTRUMENTS (MULTIPLE)
        // --------------------------------
        if (!empty($data['instruments'])) {
            $instruments = array_filter(array_map('trim', explode(',', $data['instruments'])));
            foreach ($instruments as $term) {
                if (!term_exists($term, 'instrument')) wp_insert_term($term, 'instrument');
            }
            wp_set_object_terms($post_id, $instruments, 'instrument', false);
        }

        // --------------------------------
        // PRIMARY INSTRUMENT
        // --------------------------------
        if (!empty($data['primary_category'])) {

            $primary = trim($data['primary_category']);

            if (!term_exists($primary, 'instrument')) {
                wp_insert_term($primary, 'instrument');
            }

            $termObj = get_term_by('name', $primary, 'instrument');

            if ($termObj) {
                update_post_meta($post_id, '_yoast_wpseo_primary_instrument', $termObj->term_id);
                wp_set_object_terms($post_id, [$primary], 'instrument', true);
            }
        }

        // --------------------------------
        // GEAR
        // --------------------------------
        if (!empty($data['gear'])) {
            $gears = array_filter(array_map('trim', explode(',', $data['gear'])));
            foreach ($gears as $term) {
                if (!term_exists($term, 'gear')) wp_insert_term($term, 'gear');
            }
            wp_set_object_terms($post_id, $gears, 'gear', false);
        }

        // --------------------------------
        // FEATURED IMAGE
        // --------------------------------
        if (!empty($data['featured_image'])) {
            $img = $image_dir . $data['featured_image'];

            if (file_exists($img)) {
                $upload = wp_upload_bits(basename($img), null, file_get_contents($img));

                if (empty($upload['error'])) {
                    $filetype  = wp_check_filetype($upload['file']);
                    $attach_id = wp_insert_attachment([
                        'post_mime_type' => $filetype['type'],
                        'post_title'     => sanitize_file_name($data['featured_image']),
                        'post_status'    => 'inherit'
                    ], $upload['file'], $post_id);

                    require_once ABSPATH . 'wp-admin/includes/image.php';

                    $meta = wp_generate_attachment_metadata($attach_id, $upload['file']);
                    wp_update_attachment_metadata($attach_id, $meta);

                    set_post_thumbnail($post_id, $attach_id);
                }
            }
        }
    }

    fclose($handle);

    wp_die("<h2>Artist Import Completed — $count rows processed.</h2>");
});

function csv_text_to_blocks( $text ) {
    $text = trim( $text );

    if ( empty( $text ) ) {
        return '';
    }

    // Split paragraphs by double line breaks
    $paragraphs = preg_split( "/\R{2,}/", $text );

    $blocks = '';

    foreach ( $paragraphs as $p ) {
        $p = trim( $p );
        if ( $p === '' ) continue;

        $blocks .= "<!-- wp:paragraph -->\n";
        $blocks .= '<p>' . esc_html( $p ) . "</p>\n";
        $blocks .= "<!-- /wp:paragraph -->\n\n";
    }

    return $blocks;
}
