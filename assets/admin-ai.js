(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var container = document.querySelector('.ar-ai-blocks');
        var templateScript = document.getElementById('ar-ai-block-template');
        var addButton = document.getElementById('ar-add-ai-block');

        if (! container || ! templateScript || ! addButton) {
            return;
        }

        var nextIndex = parseInt(container.dataset.nextIndex, 10) || 0;
        var templateHtml = templateScript.textContent || templateScript.innerHTML;

        var addBlock = function () {
            var blockMarkup = templateHtml.replace(/__INDEX__/g, nextIndex);
            container.insertAdjacentHTML('beforeend', blockMarkup);
            nextIndex += 1;
            container.dataset.nextIndex = nextIndex;
        };

        addButton.addEventListener('click', function (event) {
            event.preventDefault();
            addBlock();
        });

        container.addEventListener('click', function (event) {
            if (event.target.matches('.ar-ai-remove-row')) {
                event.preventDefault();
                var block = event.target.closest('.ar-ai-block');
                if (block) {
                    block.remove();
                }
            }
        });
    });
})();
