(function ( wp, $ ) {
    if ( ! wp || ! wp.media || ! wp.media.view ) {
        return;
    }

    var labels = ( window.wpaphMediaLibrary && window.wpaphMediaLibrary.labels ) || {};

    function formatUsage( usage ) {
        var label = labels.usage || 'Usage count';
        return label + ': ' + usage;
    }

    function ensureUsageDisplay( view ) {
        if ( ! view || ! view.model ) {
            return;
        }

        var usage = view.model.get( 'usageCount' );

        if ( typeof usage === 'undefined' || usage === null ) {
            return;
        }

        usage = parseInt( usage, 10 );

        if ( isNaN( usage ) ) {
            return;
        }

        var $details = view.$( '.attachment-info .details' );

        if ( ! $details.length ) {
            return;
        }

        var $usageRow = $details.find( '.wpaph-usage-count' );

        if ( ! $usageRow.length ) {
            $usageRow = $( '<div class="wpaph-usage-count" />' );
            $details.append( $usageRow );
        }

        $usageRow.text( formatUsage( usage ) );
    }

    function enhanceRender( proto ) {
        if ( ! proto || proto.wpaphEnhanced ) {
            return;
        }

        var originalRender = proto.render;

        proto.render = function () {
            var result = originalRender.apply( this, arguments );
            ensureUsageDisplay( this );
            return result;
        };

        proto.wpaphEnhanced = true;
    }

    enhanceRender( wp.media.view.Attachment.Details.prototype );

    if ( wp.media.view.Attachment.DetailsTwoColumn ) {
        enhanceRender( wp.media.view.Attachment.DetailsTwoColumn.prototype );
    }

    if ( wp.media.view.Attachment.Details.TwoColumn ) {
        enhanceRender( wp.media.view.Attachment.Details.TwoColumn.prototype );
    }
})( window.wp, window.jQuery );
