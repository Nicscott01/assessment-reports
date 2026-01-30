(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var rows = document.querySelectorAll('.ar-choice-row');
        Array.prototype.forEach.call(rows, function (row) {
            var checkbox = row.querySelector('.ar-mapping-checkbox');
            var weightInput = row.querySelector('.ar-weight-input');
            if (!checkbox || !weightInput) {
                return;
            }

            weightInput.dataset.defaultWeight = weightInput.value || '1';

            var updateState = function () {
                var isChecked = checkbox.checked;
                weightInput.disabled = !isChecked;
                if (!isChecked) {
                    weightInput.value = weightInput.dataset.defaultWeight;
                }
            };

            checkbox.addEventListener('change', updateState);
            updateState();
        });
    });
})();
