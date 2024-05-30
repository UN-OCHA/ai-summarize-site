/* global once */
(function () {
  'use strict';

  Drupal.behaviors.aiSummerizeCopy = {
    attach: function (context, settings) {
      this.toggleFeedback(context);
      this.copyToClipboard(context);
    },

    /**
     * Detailed feedback toggle
     *
     * When both modes are shown simultaneously, we hide the detailed feedback
     * until someone toggles it. The feedbackObservers function will handle the
     * scrolling, but this code marks an individual detailed feedback <details>
     * as non-hidden.
     */
    toggleFeedback: function (context) {
      const thumbs = context.querySelectorAll('[name="field_thumbs"] ~ label');
      thumbs.forEach((el) => {
        el.addEventListener('click', (ev) => {
          // Find the <details> associated with this Answer.
          const targetFeedback = document.querySelector(`.ai-summarize--feedback-detailed`);
          targetFeedback.style.display = 'block';
        });
      });
    },

    /**
     * Copy to Clipboard
     *
     * Needs to be run every time the form reloads. It will find all the copy
     * buttons and attach an event listener that copies individual answers to
     * the user's clipboard.
     *
     * Adapted from CD Social Links in CD v9.4.0
     *
     * @see https://github.com/UN-OCHA/common_design/blob/v9.4.0/libraries/cd-social-links/cd-social-links.js
     */
    copyToClipboard: function (context) {
      // Collect all "copy" URL buttons.
      const copyButtons = context.querySelectorAll('.feedback-button--copy');

      // Process links so they copy URL to clipboard.
      copyButtons.forEach(function (el) {
        // Event listener so people can copy to clipboard.
        //
        // As of hook_update_10005() the button is hooked up to the Drupal form
        // so that it can submit and record that the copy button was pressed.
        // Drupal handles displaying success feedback to the user. This code is
        // still showing feedback in case of failure to copy.
        el.addEventListener('click', function (ev) {
          var tempInput = document.createElement('input');
          var textToCopy = document.querySelector(el.getAttribute('data-to-copy')).innerHTML.replaceAll('<br>', '\n');

          try {
            if (navigator.clipboard) {
              // Easy way possible?
              navigator.clipboard.writeText(textToCopy);
            }
            else {
              // Legacy method
              document.body.appendChild(tempInput);
              tempInput.value = textToCopy;
              tempInput.select();
              document.execCommand('copy');
              document.body.removeChild(tempInput);
            }
          }
          catch (err) {
            // Log errors to console.
            console.error(err);
          }

          ev.stopImmediatePropagation();
        });
      });
    },
  };
})();
