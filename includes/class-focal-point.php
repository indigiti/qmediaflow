<?php
namespace MediaFlow;

/** Manual focal point metadata; manual values remain authoritative. */
final class Focal_Point {
    private static array $cache = array();

    public function hooks(): void {
        add_filter( 'attachment_fields_to_edit', array( $this, 'fields' ), 20, 2 );
        add_filter( 'attachment_fields_to_save', array( $this, 'save' ), 20, 2 );
    }

    /** @return array{0:int,1:int} */
    public static function get( int $attachment_id ): array {
        if ( isset( self::$cache[ $attachment_id ] ) ) { return self::$cache[ $attachment_id ]; }
        $x = get_post_meta( $attachment_id, '_qmediaflow_focal_x', true );
        $y = get_post_meta( $attachment_id, '_qmediaflow_focal_y', true );
        $x = '' === $x ? 50 : max( 0, min( 100, (int) $x ) );
        $y = '' === $y ? 50 : max( 0, min( 100, (int) $y ) );
        return self::$cache[ $attachment_id ] = array( $x, $y );
    }

    public function fields( array $fields, \WP_Post $attachment ): array {
        if ( ! wp_attachment_is_image( $attachment->ID ) ) { return $fields; }
        [ $x, $y ] = self::get( (int) $attachment->ID );
        $fields['qmediaflow_focal_x'] = array(
            'label' => 'QMediaFlow focal X',
            'input' => 'html',
            'html'  => '<input type="number" min="0" max="100" step="1" name="attachments[' . (int) $attachment->ID . '][qmediaflow_focal_x]" value="' . esc_attr( (string) $x ) . '"> %',
            'helps' => 'Horizontal focal point for cropped QMediaFlow variants. 0 = left, 50 = center, 100 = right.',
        );
        $fields['qmediaflow_focal_y'] = array(
            'label' => 'QMediaFlow focal Y',
            'input' => 'html',
            'html'  => '<input type="number" min="0" max="100" step="1" name="attachments[' . (int) $attachment->ID . '][qmediaflow_focal_y]" value="' . esc_attr( (string) $y ) . '"> %',
            'helps' => 'Vertical focal point for cropped QMediaFlow variants. 0 = top, 50 = center, 100 = bottom.',
        );
        return $fields;
    }

    public function save( array $post, array $attachment ): array {
        $id = absint( $post['ID'] ?? 0 );
        if ( $id < 1 || ! current_user_can( 'edit_post', $id ) ) { return $post; }
        $old = self::get( $id );
        $x = isset( $attachment['qmediaflow_focal_x'] ) ? max( 0, min( 100, absint( $attachment['qmediaflow_focal_x'] ) ) ) : $old[0];
        $y = isset( $attachment['qmediaflow_focal_y'] ) ? max( 0, min( 100, absint( $attachment['qmediaflow_focal_y'] ) ) ) : $old[1];
        update_post_meta( $id, '_qmediaflow_focal_x', $x );
        update_post_meta( $id, '_qmediaflow_focal_y', $y );
        self::$cache[ $id ] = array( $x, $y );
        if ( $old[0] !== $x || $old[1] !== $y ) {
            Telemetry::event( 'focal_point_changed' );
            do_action( 'qmediaflow_focal_changed', $id, $x, $y, $old );
        }
        return $post;
    }
}
