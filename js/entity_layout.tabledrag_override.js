(function ($, window, Drupal) {

  'use strict';
  Drupal.behaviors.blockDrag = {
    attach: function (context, settings) {
      $.each(Drupal.tableDrag, function(id, tableDrag) {
        tableDrag.onDrop = function () {
          var $rowElement = $(this.rowObject.element);
          var $table = $rowElement.closest('table');

          var $oldRegionIdInput = $rowElement.find('[data-region-id-input]');
          var oldRegionId = $oldRegionIdInput.val();
          var newRegionId = $rowElement.prevAll('[data-region-id]').attr('data-region-id');
          if (oldRegionId !== newRegionId) {
            $oldRegionIdInput.val(newRegionId);
          }

          var $weightInput = $rowElement.find('.item-weight');
          $weightInput.removeClass('item-weight-' + oldRegionId).addClass('item-weight-' + newRegionId);

          var weight = -Math.round($table.find('.draggable').length / 2);

          $table.find('[data-region-id=' + newRegionId + ']').nextUntil('[data-region-id]')
            .find('.item-weight').val(function () {

            return ++weight;
          });

        };
      });
    }
  }
})(jQuery, window, Drupal);
