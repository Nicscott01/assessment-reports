(function () {
    function parseJsonAttribute(element, key) {
        var raw = element.getAttribute(key);
        if (!raw) {
            return {};
        }

        try {
            return JSON.parse(raw);
        } catch (error) {
            return {};
        }
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getFieldRulePrefix(builder) {
        return builder.getAttribute('data-field-rule-prefix') || 'ar_score_profile[builder][field_rules]';
    }

    function optionMarkup(builder, ruleIndex, options) {
        var fieldRulePrefix = getFieldRulePrefix(builder);
        if (!options.length) {
            return '<p class="description">Select a form field with predefined options to configure answer scoring.</p>';
        }

        return '<div class="ar-field-rule-options-grid">' + options.map(function (option, optionIndex) {
            var value = option.value || '';
            var label = option.label || value;
            var title = label !== value ? '<div class="ar-field-rule-option__value">' + escapeHtml(value) + '</div>' : '';
            return [
                '<div class="ar-field-rule-option">',
                '<div class="ar-field-rule-option__label">' + escapeHtml(label) + '</div>',
                title,
                '<input type="hidden" name="' + escapeHtml(fieldRulePrefix + '[' + ruleIndex + '][options][' + optionIndex + '][value]') + '" value="' + escapeHtml(value) + '">',
                '<input type="hidden" name="' + escapeHtml(fieldRulePrefix + '[' + ruleIndex + '][options][' + optionIndex + '][label]') + '" value="' + escapeHtml(label) + '">',
                '<input type="number" step="0.01" class="small-text" name="' + escapeHtml(fieldRulePrefix + '[' + ruleIndex + '][options][' + optionIndex + '][score]') + '" value="" placeholder="0">',
                '</div>'
            ].join('');
        }).join('') + '</div>';
    }

    function buildFieldOptions(fields, selectedField, disabledFields) {
        return ['<option value="">Select a field</option>'].concat(fields.filter(function (field) {
            return Array.isArray(field.options) && field.options.length > 0;
        }).map(function (field) {
            var selected = field.name === selectedField ? ' selected' : '';
            var disabled = field.name !== selectedField && disabledFields.indexOf(field.name) !== -1 ? ' disabled' : '';
            return '<option value="' + escapeHtml(field.name) + '"' + selected + disabled + '>' + escapeHtml(field.label) + '</option>';
        })).join('');
    }

    function buildFocusOptions(fields, selectedValue) {
        return ['<option value="">No focus field</option>'].concat(fields.map(function (field) {
            var selected = field.name === selectedValue ? ' selected' : '';
            return '<option value="' + escapeHtml(field.name) + '"' + selected + '>' + escapeHtml(field.label) + '</option>';
        })).join('');
    }

    function buildDimensionOptions(chartConfig, selectedChart, selectedDimension) {
        var chart = chartConfig[selectedChart];
        var dimensions = chart && Array.isArray(chart.dimensions) ? chart.dimensions : [];

        return ['<option value="">Select a dimension</option>'].concat(dimensions.map(function (dimension) {
            var selected = dimension.key === selectedDimension ? ' selected' : '';
            return '<option value="' + escapeHtml(dimension.key) + '"' + selected + '>' + escapeHtml(dimension.label) + '</option>';
        })).join('');
    }

    function createRuleRow(builder, ruleIndex) {
        var fieldRulePrefix = getFieldRulePrefix(builder);
        var formCatalog = parseJsonAttribute(builder, 'data-form-catalog');
        var chartConfig = parseJsonAttribute(builder, 'data-chart-config');
        var formSelect = builder.querySelector('.ar-score-profile-form-select');
        var selectedFormId = formSelect ? formSelect.value : '';
        var fields = formCatalog[selectedFormId] && Array.isArray(formCatalog[selectedFormId].fields)
            ? formCatalog[selectedFormId].fields
            : [];

        var chartOptions = ['<option value="">Select a chart</option>'].concat(Object.keys(chartConfig).map(function (chartKey) {
            return '<option value="' + escapeHtml(chartKey) + '">' + escapeHtml(chartConfig[chartKey].label) + '</option>';
        })).join('');

        var wrapper = document.createElement('div');
        wrapper.className = 'ar-field-rule-row';
        wrapper.setAttribute('data-rule-index', String(ruleIndex));
        wrapper.innerHTML = [
            '<div class="ar-field-rule-row__top">',
            '<p><label class="ar-field-label">Field</label><select class="widefat ar-field-rule-field" name="' + escapeHtml(fieldRulePrefix + '[' + ruleIndex + '][field]') + '">' + buildFieldOptions(fields, '', []) + '</select></p>',
            '<p><label class="ar-field-label">Chart</label><select class="widefat ar-field-rule-chart" name="' + escapeHtml(fieldRulePrefix + '[' + ruleIndex + '][chart]') + '">' + chartOptions + '</select></p>',
            '<p><label class="ar-field-label">Dimension</label><select class="widefat ar-field-rule-dimension" name="' + escapeHtml(fieldRulePrefix + '[' + ruleIndex + '][dimension]') + '"><option value="">Select a dimension</option></select></p>',
            '<p><label class="ar-field-label">Aggregation</label><select class="widefat" name="' + escapeHtml(fieldRulePrefix + '[' + ruleIndex + '][aggregation]') + '"><option value="average">Average</option><option value="sum">Sum</option><option value="max">Max</option><option value="min">Min</option><option value="last">Last</option></select></p>',
            '<p><label class="ar-field-label">Multiplier</label><input type="number" step="0.01" class="widefat" name="' + escapeHtml(fieldRulePrefix + '[' + ruleIndex + '][multiplier]') + '" value="1"></p>',
            '<p class="ar-field-rule-row__actions"><button type="button" class="button-link-delete ar-score-profile-remove-rule">Remove</button></p>',
            '</div>',
            '<div class="ar-field-rule-options"><strong>Answer Scoring</strong><div class="ar-field-rule-options-body">' + optionMarkup(builder, ruleIndex, []) + '</div></div>'
        ].join('');

        return wrapper;
    }

    function getNextRuleIndex(rulesBody) {
        var maxIndex = -1;

        Array.prototype.forEach.call(rulesBody.querySelectorAll('.ar-field-rule-row'), function (row) {
            var index = parseInt(row.getAttribute('data-rule-index') || '-1', 10);
            if (!isNaN(index) && index > maxIndex) {
                maxIndex = index;
            }
        });

        return maxIndex + 1;
    }

    function renumberRuleRows(builder) {
        var rulesBody = builder.querySelector('.ar-score-field-rules-body');
        var fieldRulePrefix = getFieldRulePrefix(builder);

        if (!rulesBody) {
            return;
        }

        Array.prototype.forEach.call(rulesBody.querySelectorAll('.ar-field-rule-row'), function (row, rowIndex) {
            row.setAttribute('data-rule-index', String(rowIndex));

            Array.prototype.forEach.call(row.querySelectorAll('input[name], select[name], textarea[name]'), function (input) {
                var name = input.getAttribute('name') || '';
                if (name.indexOf(fieldRulePrefix + '[') !== 0) {
                    return;
                }

                input.setAttribute(
                    'name',
                    name.replace(
                        new RegExp('^' + fieldRulePrefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\[\\d+\\]'),
                        fieldRulePrefix + '[' + rowIndex + ']'
                    )
                );
            });
        });
    }

    function refreshFocusSelect(builder) {
        var formCatalog = parseJsonAttribute(builder, 'data-form-catalog');
        var formSelect = builder.querySelector('.ar-score-profile-form-select');
        var focusSelect = builder.querySelector('.ar-score-profile-focus-select');
        if (!formSelect || !focusSelect) {
            return;
        }

        var selectedFormId = formSelect.value;
        var currentValue = focusSelect.value;
        var fields = formCatalog[selectedFormId] && Array.isArray(formCatalog[selectedFormId].fields)
            ? formCatalog[selectedFormId].fields
            : [];

        focusSelect.innerHTML = buildFocusOptions(fields, currentValue);
    }

    function refreshRuleRow(row, builder) {
        var formCatalog = parseJsonAttribute(builder, 'data-form-catalog');
        var chartConfig = parseJsonAttribute(builder, 'data-chart-config');
        var formSelect = builder.querySelector('.ar-score-profile-form-select');
        var selectedFormId = formSelect ? formSelect.value : '';
        var fields = formCatalog[selectedFormId] && Array.isArray(formCatalog[selectedFormId].fields)
            ? formCatalog[selectedFormId].fields
            : [];

        var fieldSelect = row.querySelector('.ar-field-rule-field');
        var chartSelect = row.querySelector('.ar-field-rule-chart');
        var dimensionSelect = row.querySelector('.ar-field-rule-dimension');
        var optionsBody = row.querySelector('.ar-field-rule-options-body');
        var ruleIndex = row.getAttribute('data-rule-index');

        if (!fieldSelect || !chartSelect || !dimensionSelect || !optionsBody) {
            return;
        }

        var disabledFields = getSelectedFields(builder, row);
        var selectedField = fieldSelect.value;
        var selectedChart = chartSelect.value;
        var selectedDimension = dimensionSelect.value;

        fieldSelect.innerHTML = buildFieldOptions(fields, selectedField, disabledFields);
        dimensionSelect.innerHTML = buildDimensionOptions(chartConfig, selectedChart, selectedDimension);

        var field = fields.find(function (item) {
            return item.name === selectedField;
        });
        var options = field && Array.isArray(field.options) ? field.options : [];
        var existingScores = {};

        Array.prototype.forEach.call(optionsBody.querySelectorAll('input[type="number"]'), function (input) {
            var name = input.getAttribute('name') || '';
            var match = name.match(/\[options\]\[(\d+)\]\[score\]$/);
            if (!match) {
                return;
            }

            var valueInput = optionsBody.querySelector('input[name="' + name.replace('[score]', '[value]') + '"]');
            if (valueInput) {
                existingScores[valueInput.value] = input.value;
            }
        });

        optionsBody.innerHTML = optionMarkup(builder, ruleIndex, options);

        Array.prototype.forEach.call(optionsBody.querySelectorAll('input[type="number"]'), function (input) {
            var valueInput = optionsBody.querySelector('input[name="' + input.name.replace('[score]', '[value]') + '"]');
            if (valueInput && Object.prototype.hasOwnProperty.call(existingScores, valueInput.value)) {
                input.value = existingScores[valueInput.value];
            }
        });
    }

    function getSelectedFields(builder, currentRow) {
        var selected = [];

        Array.prototype.forEach.call(builder.querySelectorAll('.ar-field-rule-row'), function (row) {
            if (currentRow && row === currentRow) {
                return;
            }

            var fieldSelect = row.querySelector('.ar-field-rule-field');
            if (!fieldSelect || !fieldSelect.value) {
                return;
            }

            selected.push(fieldSelect.value);
        });

        return selected;
    }

    function refreshAllRuleRows(builder) {
        var rulesBody = builder.querySelector('.ar-score-field-rules-body');
        if (!rulesBody) {
            return;
        }

        Array.prototype.forEach.call(rulesBody.querySelectorAll('.ar-field-rule-row'), function (row) {
            refreshRuleRow(row, builder);
        });
    }

    function bindBuilder(builder) {
        var rulesBody = builder.querySelector('.ar-score-field-rules-body');
        var addRuleButton = builder.querySelector('.ar-score-profile-add-rule');
        var formSelect = builder.querySelector('.ar-score-profile-form-select');

        if (!rulesBody) {
            return;
        }

        refreshAllRuleRows(builder);

        builder.addEventListener('change', function (event) {
            if (event.target.classList.contains('ar-score-profile-form-select')) {
                refreshFocusSelect(builder);
                refreshAllRuleRows(builder);
                return;
            }

            if (event.target.classList.contains('ar-field-rule-field') || event.target.classList.contains('ar-field-rule-chart')) {
                var row = event.target.closest('.ar-field-rule-row');
                if (row) {
                    refreshRuleRow(row, builder);
                    refreshAllRuleRows(builder);
                }
            }
        });

        builder.addEventListener('click', function (event) {
            if (event.target.classList.contains('ar-score-profile-add-rule')) {
                event.preventDefault();
                var nextIndex = getNextRuleIndex(rulesBody);
                var row = createRuleRow(builder, nextIndex);
                rulesBody.appendChild(row);
                refreshAllRuleRows(builder);
                return;
            }

            if (event.target.classList.contains('ar-score-profile-remove-rule')) {
                event.preventDefault();
                var row = event.target.closest('.ar-field-rule-row');
                if (row) {
                    row.remove();
                    refreshAllRuleRows(builder);
                }
            }
        });

        var form = builder.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                renumberRuleRows(builder);
            });
        }

        refreshFocusSelect(builder);
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.ar-score-profile-builder'), bindBuilder);
    });
})();
