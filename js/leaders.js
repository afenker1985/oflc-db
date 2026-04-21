(function () {
    var page = document.querySelector('.leaders-page');
    var tableBody = document.getElementById('leaders-table-body');
    var addButton = document.getElementById('add-leader-button');
    var removeButton = document.getElementById('remove-leader-button');
    var cancelRemoveButton = document.getElementById('cancel-remove-leader-button');
    var removeAlert = document.getElementById('leaders-remove-alert');
    var removeName = document.getElementById('leaders-remove-name');
    var removeConfirmButton = document.getElementById('leaders-remove-confirm-button');
    var removeAlertCancelButton = document.getElementById('leaders-remove-cancel-button');
    var messageSlot = document.getElementById('leaders-message');
    var emptyRowId = 'leaders-empty-row';
    var draftRow = null;
    var isRemoveMode = false;

    if (!page || !tableBody || !addButton || !removeButton || !cancelRemoveButton || !removeAlert || !removeName || !removeConfirmButton || !removeAlertCancelButton || !messageSlot) {
        return;
    }

    function normalizeSortValue(value) {
        return String(value || '').toLowerCase().trim();
    }

    function clearMessage() {
        messageSlot.className = 'leaders-message-slot';
        messageSlot.textContent = '';
    }

    function showMessage(type, text) {
        messageSlot.className = type === 'error' ? 'planning-error' : 'planning-success';
        messageSlot.textContent = text;
    }

    function findEmptyRow() {
        return document.getElementById(emptyRowId);
    }

    function removeEmptyRow() {
        var emptyRow = findEmptyRow();

        if (emptyRow && emptyRow.parentNode) {
            emptyRow.parentNode.removeChild(emptyRow);
        }
    }

    function ensureEmptyRow() {
        if (tableBody.querySelector('.leaders-row[data-leader-id]') || findEmptyRow() || draftRow) {
            return;
        }

        var row = document.createElement('div');

        row.id = emptyRowId;
        row.className = 'leaders-empty-row';
        row.textContent = 'No leaders were found in the database.';
        tableBody.appendChild(row);
    }

    function compareRows(rowA, rowB) {
        var lastA = normalizeSortValue(rowA.getAttribute('data-sort-last'));
        var lastB = normalizeSortValue(rowB.getAttribute('data-sort-last'));
        var firstA = normalizeSortValue(rowA.getAttribute('data-sort-first'));
        var firstB = normalizeSortValue(rowB.getAttribute('data-sort-first'));
        var idA = Number(rowA.getAttribute('data-leader-id') || 0);
        var idB = Number(rowB.getAttribute('data-leader-id') || 0);

        if (lastA < lastB) {
            return -1;
        }

        if (lastA > lastB) {
            return 1;
        }

        if (firstA < firstB) {
            return -1;
        }

        if (firstA > firstB) {
            return 1;
        }

        return idA - idB;
    }

    function sortLeaderRows() {
        var rows = Array.prototype.slice.call(tableBody.querySelectorAll('.leaders-row[data-leader-id]'));

        rows.sort(compareRows);
        rows.forEach(function (row) {
            tableBody.appendChild(row);
        });

        if (draftRow && draftRow.parentNode === tableBody) {
            tableBody.appendChild(draftRow);
        }
    }

    function setRowSaving(row, isSaving) {
        if (!row) {
            return;
        }

        row.classList.toggle('is-saving', !!isSaving);
    }

    function requestLeaderActiveToggle(checkbox) {
        var row;
        var body;
        var previousChecked;

        if (!checkbox || checkbox.disabled) {
            return;
        }

        row = checkbox.closest('.leaders-row');
        previousChecked = !checkbox.checked;
        body = new URLSearchParams();
        body.set('id', checkbox.getAttribute('data-leader-id'));
        body.set('is_active', checkbox.checked ? '1' : '0');

        checkbox.disabled = true;
        setRowSaving(row, true);

        fetch('ajax/update_leader_active.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return {
                        ok: response.ok,
                        data: data
                    };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data || !result.data.success) {
                    throw new Error(result.data && result.data.message ? result.data.message : 'Unable to update leader.');
                }

                checkbox.disabled = false;
                setRowSaving(row, false);
                clearMessage();
            })
            .catch(function (error) {
                checkbox.checked = previousChecked;
                checkbox.disabled = false;
                setRowSaving(row, false);
                showMessage('error', error.message || 'Unable to update leader.');
            });
    }

    function createActionButton(text, className) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.textContent = text;
        return button;
    }

    function createLeaderRow(leader) {
        var row = document.createElement('div');
        var selectCell = document.createElement('div');
        var firstCell = document.createElement('div');
        var lastCell = document.createElement('div');
        var activeCell = document.createElement('div');
        var radio = document.createElement('input');
        var checkbox = document.createElement('input');
        var fullName = [leader.first_name, leader.last_name].join(' ').replace(/\s+/g, ' ').trim();

        row.className = 'leaders-row';
        row.setAttribute('data-leader-id', String(leader.id));
        row.setAttribute('data-sort-first', leader.first_name);
        row.setAttribute('data-sort-last', leader.last_name);

        selectCell.className = 'leaders-select-column';

        radio.type = 'radio';
        radio.className = 'leaders-select-radio';
        radio.name = 'leader_remove_id';
        radio.value = String(leader.id);
        radio.setAttribute('aria-label', 'Select ' + fullName + ' for removal');
        selectCell.appendChild(radio);

        firstCell.className = 'leaders-first-name-cell';
        firstCell.textContent = leader.first_name;

        lastCell.className = 'leaders-last-name-cell';
        lastCell.textContent = leader.last_name;

        activeCell.className = 'leaders-active-column';

        checkbox.type = 'checkbox';
        checkbox.className = 'leaders-active-checkbox js-leader-active';
        checkbox.setAttribute('data-leader-id', String(leader.id));
        checkbox.setAttribute('aria-label', 'Set active for ' + fullName);
        checkbox.checked = Number(leader.is_active) === 1;

        row.appendChild(selectCell);
        activeCell.appendChild(checkbox);
        row.appendChild(firstCell);
        row.appendChild(lastCell);
        row.appendChild(activeCell);

        return row;
    }

    function setAddButtonEnabled(isEnabled) {
        addButton.disabled = !isEnabled;
    }

    function hideRemoveAlert() {
        removeAlert.hidden = true;
        removeConfirmButton.disabled = false;
        removeAlertCancelButton.disabled = false;
    }

    function showRemoveAlert() {
        removeAlert.hidden = false;
    }

    function getLeaderDisplayNameFromRow(row) {
        var firstNameCell;
        var lastNameCell;
        var firstName;
        var lastName;

        if (!row) {
            return 'this leader';
        }

        firstNameCell = row.querySelector('.leaders-first-name-cell');
        lastNameCell = row.querySelector('.leaders-last-name-cell');
        firstName = firstNameCell ? firstNameCell.textContent.replace(/\s+/g, ' ').trim() : '';
        lastName = lastNameCell ? lastNameCell.textContent.replace(/\s+/g, ' ').trim() : '';

        return (firstName + ' ' + lastName).replace(/\s+/g, ' ').trim() || 'this leader';
    }

    function getSelectedRadio() {
        return tableBody.querySelector('.leaders-select-radio:checked');
    }

    function clearSelectedRadio() {
        var selectedRadio = getSelectedRadio();

        if (selectedRadio) {
            selectedRadio.checked = false;
        }

        Array.prototype.forEach.call(tableBody.querySelectorAll('.leaders-row.is-selected-remove'), function (row) {
            row.classList.remove('is-selected-remove');
        });
    }

    function refreshRemoveSelectionState() {
        Array.prototype.forEach.call(tableBody.querySelectorAll('.leaders-row[data-leader-id]'), function (row) {
            var radio = row.querySelector('.leaders-select-radio');
            row.classList.toggle('is-selected-remove', !!(radio && radio.checked));
        });
    }

    function setRemoveMode(isEnabled) {
        isRemoveMode = !!isEnabled;
        page.classList.toggle('is-remove-mode', isRemoveMode);
        removeButton.classList.toggle('fill-hymns-button', !isRemoveMode);
        removeButton.classList.toggle('delete-hymn-button', isRemoveMode);
        cancelRemoveButton.hidden = !isRemoveMode;
        cancelRemoveButton.disabled = !isRemoveMode;
        hideRemoveAlert();
        clearSelectedRadio();
    }

    function buildDraftRow() {
        var row = document.createElement('div');
        var selectCell = document.createElement('div');
        var firstCell = document.createElement('div');
        var lastCell = document.createElement('div');
        var actionCell = document.createElement('div');
        var firstInput = document.createElement('input');
        var lastInput = document.createElement('input');
        var actionWrap = document.createElement('div');
        var saveButton = createActionButton('Save', 'add-hymn-button');
        var cancelButton = createActionButton('Cancel', 'clear-list-button');

        row.className = 'leaders-row is-draft';

        firstInput.type = 'text';
        firstInput.className = 'leaders-row-input';
        firstInput.placeholder = 'First name';
        firstInput.maxLength = 100;

        lastInput.type = 'text';
        lastInput.className = 'leaders-row-input';
        lastInput.placeholder = 'Last name';
        lastInput.maxLength = 100;

        selectCell.className = 'leaders-select-column';
        actionCell.className = 'leaders-active-column';
        actionWrap.className = 'leaders-inline-actions';
        actionWrap.appendChild(saveButton);
        actionWrap.appendChild(cancelButton);

        selectCell.textContent = '';
        firstCell.appendChild(firstInput);
        lastCell.appendChild(lastInput);
        actionCell.appendChild(actionWrap);

        row.appendChild(selectCell);
        row.appendChild(firstCell);
        row.appendChild(lastCell);
        row.appendChild(actionCell);

        saveButton.addEventListener('click', function () {
            var firstName = firstInput.value.replace(/\s+/g, ' ').trim();
            var lastName = lastInput.value.replace(/\s+/g, ' ').trim();
            var body;

            if (firstName === '' || lastName === '') {
                showMessage('error', 'First name and last name are required.');
                if (firstName === '') {
                    firstInput.focus();
                } else {
                    lastInput.focus();
                }
                return;
            }

            setRowSaving(row, true);
            firstInput.disabled = true;
            lastInput.disabled = true;
            saveButton.disabled = true;
            cancelButton.disabled = true;

            body = new URLSearchParams();
            body.set('first_name', firstName);
            body.set('last_name', lastName);

            fetch('ajax/add_leader.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return {
                            ok: response.ok,
                            data: data
                        };
                    });
                })
                .then(function (result) {
                    var leaderRow;

                    if (!result.ok || !result.data || !result.data.success || !result.data.leader) {
                        throw new Error(result.data && result.data.message ? result.data.message : 'Unable to add leader.');
                    }

                    leaderRow = createLeaderRow(result.data.leader);

                    if (row.parentNode) {
                        row.parentNode.removeChild(row);
                    }

                    draftRow = null;
                    setAddButtonEnabled(true);
                    removeButton.disabled = false;
                    cancelRemoveButton.disabled = !isRemoveMode;
                    hideRemoveAlert();
                    removeEmptyRow();
                    tableBody.appendChild(leaderRow);
                    sortLeaderRows();
                    clearMessage();
                    showMessage('success', 'Leader added.');
                })
                .catch(function (error) {
                    setRowSaving(row, false);
                    firstInput.disabled = false;
                    lastInput.disabled = false;
                    saveButton.disabled = false;
                    cancelButton.disabled = false;
                    showMessage('error', error.message || 'Unable to add leader.');
                });
        });

        cancelButton.addEventListener('click', function () {
            if (row.parentNode) {
                row.parentNode.removeChild(row);
            }

            draftRow = null;
            setAddButtonEnabled(true);
            removeButton.disabled = false;
            cancelRemoveButton.disabled = !isRemoveMode;
            hideRemoveAlert();
            ensureEmptyRow();
            clearMessage();
        });

        return {
            row: row,
            firstInput: firstInput
        };
    }

    tableBody.addEventListener('change', function (event) {
        var checkbox = event.target;

        if (checkbox.classList.contains('leaders-select-radio')) {
            hideRemoveAlert();
            refreshRemoveSelectionState();
            return;
        }

        if (!checkbox.classList.contains('js-leader-active')) {
            return;
        }

        requestLeaderActiveToggle(checkbox);
    });

    tableBody.addEventListener('click', function (event) {
        var row = event.target.closest('.leaders-row');
        var radio;
        var checkbox;

        if (!row || draftRow === row) {
            return;
        }

        if (
            event.target.closest('button') ||
            event.target.closest('input[type="text"]')
        ) {
            return;
        }

        if (isRemoveMode) {
            radio = row.querySelector('.leaders-select-radio');
            if (radio && !radio.checked) {
                radio.checked = true;
                hideRemoveAlert();
                refreshRemoveSelectionState();
            }
            return;
        }

        checkbox = row.querySelector('.js-leader-active');
        if (!checkbox || checkbox.disabled) {
            return;
        }

        checkbox.checked = !checkbox.checked;
        requestLeaderActiveToggle(checkbox);
    });

    addButton.addEventListener('click', function () {
        var draft;

        if (draftRow) {
            draftRow.querySelector('.leaders-row-input').focus();
            return;
        }

        setRemoveMode(false);
        clearMessage();
        removeEmptyRow();
        draft = buildDraftRow();
        draftRow = draft.row;
        tableBody.appendChild(draft.row);
        setAddButtonEnabled(false);
        removeButton.disabled = true;
        cancelRemoveButton.disabled = true;
        hideRemoveAlert();
        draft.firstInput.focus();
    });

    cancelRemoveButton.addEventListener('click', function () {
        setRemoveMode(false);
        clearMessage();
    });

    removeButton.addEventListener('click', function () {
        var selectedRadio;

        if (draftRow) {
            return;
        }

        if (!isRemoveMode) {
            clearMessage();
            setRemoveMode(true);
            return;
        }

        selectedRadio = getSelectedRadio();

        if (!selectedRadio) {
            showMessage('error', 'Select a leader to remove.');
            return;
        }

        removeName.textContent = getLeaderDisplayNameFromRow(selectedRadio.closest('.leaders-row'));
        showRemoveAlert();
    });

    removeAlertCancelButton.addEventListener('click', function () {
        setRemoveMode(false);
        clearMessage();
    });

    removeConfirmButton.addEventListener('click', function () {
        var selectedRadio = getSelectedRadio();
        var selectedRow;
        var leaderId;
        var body;

        if (!selectedRadio) {
            hideRemoveAlert();
            showMessage('error', 'Select a leader to remove.');
            return;
        }

        selectedRow = selectedRadio.closest('.leaders-row');
        leaderId = selectedRadio.value;
        body = new URLSearchParams();
        body.set('id', leaderId);

        removeButton.disabled = true;
        addButton.disabled = true;
        cancelRemoveButton.disabled = true;
        removeConfirmButton.disabled = true;
        removeAlertCancelButton.disabled = true;
        setRowSaving(selectedRow, true);

        fetch('ajax/delete_leader.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return {
                        ok: response.ok,
                        data: data
                    };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data || !result.data.success) {
                    throw new Error(result.data && result.data.message ? result.data.message : 'Unable to remove leader.');
                }

                if (selectedRow && selectedRow.parentNode) {
                    selectedRow.parentNode.removeChild(selectedRow);
                }

                setRemoveMode(false);
                removeButton.disabled = false;
                addButton.disabled = false;
                cancelRemoveButton.disabled = true;
                ensureEmptyRow();
                showMessage('success', 'Leader removed.');
            })
            .catch(function (error) {
                setRowSaving(selectedRow, false);
                removeButton.disabled = false;
                addButton.disabled = false;
                cancelRemoveButton.disabled = false;
                removeConfirmButton.disabled = false;
                removeAlertCancelButton.disabled = false;
                showMessage('error', error.message || 'Unable to remove leader.');
            });
    });
})();
