<?php
/**
 * GEAR IMPORT SCRIPT — CSV FIXED
 * Run once: /?import_gear=1
 */
add_action('init', function() {

    if ( ! isset($_GET['import_gear']) ) {
        return;
    }

    $file = get_stylesheet_directory() . '/gear.csv';

    if (!file_exists($file)) {
        wp_die("CSV not found");
    }

    if (($handle = fopen($file, "r")) !== FALSE) {

        $header = fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== FALSE) {

            $data = array_combine($header, $row);

            // Insert term
            $term = wp_insert_term($data['name'], 'gear');

            if (!is_wp_error($term)) {
                $term_id = $term['term_id'];

                // Save product_url as ACF term field
                update_field(
                    'product_url',
                    esc_url($data['product_url']),
                    'gear_' . $term_id
                );
            }
        }

        fclose($handle);
    }

    wp_die("Gear Imported!");
});
