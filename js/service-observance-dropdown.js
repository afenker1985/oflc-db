(function () {
    function asUniqueNonEmpty(values) {
        return Array.prototype.filter.call(values || [], function (name, index) {
            return String(name || '').trim() !== '' && values.indexOf(name) === index;
        });
    }

    window.oflcCreateObservanceDropdown = function (config) {
        var input = config && config.input ? config.input : null;
        var hiddenInput = config && config.hiddenInput ? config.hiddenInput : null;
        var list = config && config.list ? config.list : null;
        var serviceDateInput = config && config.serviceDateInput ? config.serviceDateInput : null;
        var dateSuggestions = config && Array.isArray(config.dateSuggestions) ? config.dateSuggestions : [];
        var allSuggestions = config && Array.isArray(config.allSuggestions) ? config.allSuggestions : [];
        var resolveDetail = config && typeof config.resolveDetail === 'function' ? config.resolveDetail : function () { return null; };
        var onSelect = config && typeof config.onSelect === 'function' ? config.onSelect : function () {};
        var onInput = config && typeof config.onInput === 'function' ? config.onInput : function () {};
        var onChange = config && typeof config.onChange === 'function' ? config.onChange : function () {};
        var requireDate = !!(config && config.requireDate);
        var isBound = false;

        function getSource(preferDateSuggestions) {
            var query = String(input && input.value ? input.value : '').trim().toLowerCase();
            var source = dateSuggestions;

            if (source.length === 0) {
                source = allSuggestions;
            } else if (!preferDateSuggestions && query !== '') {
                source = Array.prototype.some.call(dateSuggestions, function (name) {
                    return String(name || '').toLowerCase().indexOf(query) !== -1;
                }) ? dateSuggestions : allSuggestions;
            }

            if (preferDateSuggestions && query !== '') {
                source = Array.prototype.filter.call(source, function (name) {
                    return String(name || '').trim().toLowerCase() !== query;
                });
            }

            if (!preferDateSuggestions && query !== '') {
                source = Array.prototype.filter.call(source, function (name) {
                    return String(name || '').toLowerCase().indexOf(query) !== -1;
                });
            }

            return asUniqueNonEmpty(source);
        }

        function hide() {
            if (!list) {
                return;
            }

            list.hidden = true;
            list.classList.remove('is-visible');
            list.innerHTML = '';
        }

        function choose(name) {
            var selectedDate = String(serviceDateInput && serviceDateInput.value ? serviceDateInput.value : '').trim();
            var detail = resolveDetail(name);
            var selectedObservanceId = detail && detail.id ? String(detail.id) : '';

            if (input) {
                input.value = name;
            }
            if (hiddenInput) {
                hiddenInput.value = selectedObservanceId;
            }

            hide();
            onSelect({
                name: name,
                detail: detail,
                observanceId: selectedObservanceId,
                serviceDate: selectedDate
            });
        }

        function render(preferDateSuggestions) {
            var source;

            if (!list) {
                return;
            }

            source = getSource(preferDateSuggestions);
            list.innerHTML = '';

            Array.prototype.forEach.call(source, function (name) {
                var button = document.createElement('button');

                button.type = 'button';
                button.className = 'service-card-suggestion-item';
                button.textContent = name;
                button.setAttribute('data-observance-suggestion', name);
                button.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    choose(name);
                });
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                });

                list.appendChild(button);
            });

            list.hidden = source.length === 0;
            list.classList.toggle('is-visible', source.length > 0);
        }

        function show(preferDateSuggestions) {
            if (requireDate && (!serviceDateInput || String(serviceDateInput.value || '').trim() === '')) {
                hide();
                return;
            }

            render(!!preferDateSuggestions);
        }

        function bind() {
            if (isBound || !input || !list) {
                return;
            }

            isBound = true;

            input.addEventListener('input', function () {
                onInput();
                show(false);
            });
            input.addEventListener('change', function () {
                onChange();
            });
            input.addEventListener('focus', function () {
                show(true);
            });
            input.addEventListener('click', function () {
                show(true);
            });
            input.addEventListener('blur', function () {
                window.setTimeout(hide, 120);
            });
        }

        return {
            bind: bind,
            choose: choose,
            getSource: getSource,
            hide: hide,
            render: render,
            show: show
        };
    };
})();
