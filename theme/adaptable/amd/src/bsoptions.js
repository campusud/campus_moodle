/* jshint ignore:start */
define(['jquery', 'theme_bootstrapbase/bootstrap', 'core/log'], function($, bootstrap, log) {

    "use strict"; // jshint ;_;

    log.debug('Adaptable Bootstrap AMD opt in functions');

    return {
        init: function(hasaffix) {
            $(document).ready(function($) {
                if (hasaffix) {
                    // Check that #navwrap actually exists.
                    if($("#navwrap").length > 0) {
                        $('#navwrap').affix({
                            'offset': { top: $('#navwrap').offset().top}
                        });
                    }
                }
                $('#openoverlaymenu').click(function() {
                    $('#conditionalmenu').toggleClass('open');
                });
                $('#overlaymenuclose').click(function() {
                    $('#conditionalmenu').toggleClass('open');
                });
            });
        }
    };
});
/* jshint ignore:end */
