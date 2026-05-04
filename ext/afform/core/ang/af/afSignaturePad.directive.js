(function(angular, $, _) {
  "use strict";

  angular.module('af').directive('afSignaturePad', function($timeout) {
    return {
      restrict: 'E',
      scope: {
        uploader: '<',
        apiParams: '<',
        dataProvider: '<',
        fieldName: '@',
        required: '<'
      },
      template:
        '<div class="af-signature-pad">' +
          '<canvas class="af-signature-pad-canvas"></canvas>' +
          '<div class="af-signature-pad-controls">' +
            '<button type="button" class="btn btn-default btn-xs" ng-click="clear()">' +
              '<i class="crm-i fa-eraser" aria-hidden="true"></i> {{:: ts(\'Clear signature\') }}' +
            '</button>' +
          '</div>' +
        '</div>',
      link: function(scope, element) {
        scope.ts = CRM.ts('org.civicrm.afform');
        const canvas = element.find('canvas')[0];
        let pad = null;
        let queuedItem = null;

        // Resize the canvas to its CSS size, accounting for device pixel ratio.
        function resizeCanvas() {
          const ratio = Math.max(window.devicePixelRatio || 1, 1);
          const rect = canvas.getBoundingClientRect();
          if (!rect.width) {
            return;
          }
          canvas.width = rect.width * ratio;
          canvas.height = rect.height * ratio;
          canvas.getContext('2d').scale(ratio, ratio);
          if (pad) {
            // Resizing clears the canvas; sync the library state.
            pad.clear();
            removeQueuedItem();
          }
        }

        function removeQueuedItem() {
          if (queuedItem && scope.uploader) {
            const idx = scope.uploader.queue.indexOf(queuedItem);
            if (idx >= 0) {
              scope.uploader.removeFromQueue(idx);
            }
          }
          queuedItem = null;
        }

        function captureSignature() {
          if (!pad || pad.isEmpty()) {
            removeQueuedItem();
            return;
          }
          canvas.toBlob(function(blob) {
            if (!blob) {
              return;
            }
            removeQueuedItem();
            const file = new File([blob], 'signature.png', {type: 'image/png'});
            scope.uploader.addToQueue([file], {
              crmApiParams: scope.apiParams,
              crmDataProvider: scope.dataProvider,
              crmFieldName: scope.fieldName
            });
            queuedItem = scope.uploader.queue[scope.uploader.queue.length - 1];
          }, 'image/png');
        }

        scope.clear = function() {
          if (pad) {
            pad.clear();
          }
          removeQueuedItem();
        };

        // Defer until the canvas has been laid out.
        $timeout(function() {
          if (typeof SignaturePad === 'undefined') {
            console.error('SignaturePad library not found; signature input cannot render.');
            return;
          }
          resizeCanvas();
          pad = new SignaturePad(canvas, {backgroundColor: 'rgb(255, 255, 255)'});
          pad.addEventListener('endStroke', captureSignature);
        });

        const onResize = _.debounce(function() {
          scope.$apply(resizeCanvas);
        }, 200);
        window.addEventListener('resize', onResize);

        scope.$on('$destroy', function() {
          window.removeEventListener('resize', onResize);
          if (pad) {
            pad.off();
          }
          removeQueuedItem();
        });
      }
    };
  });

})(angular, CRM.$, CRM._);
