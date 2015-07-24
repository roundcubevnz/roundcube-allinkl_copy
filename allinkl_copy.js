/**
 * Allinkl_copy plugin script
 *
 * @licstart  The following is the entire license notice for the
 * JavaScript code in this file.
 *
 * Copyright © 2015 Dr. Tobias Quathamer
 *
 * The JavaScript code in this page is free software: you can redistribute it
 * and/or modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation, either version 3 of
 * the License, or (at your option) any later version.
 *
 * @licend  The above is the entire license notice
 * for the JavaScript code in this file.
 */

window.rcmail && rcmail.addEventListener('init', function(evt) {
    // register command handler
    rcmail.register_command('plugin.allinkl_save', function() {
        var input_copies = rcube_find_object('_copies');
        rcmail.gui_objects.copyform.submit();
    }, true);

    $('input:not(:hidden):first').focus();
});
