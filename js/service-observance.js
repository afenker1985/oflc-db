(function () {
    function normalizeLabel(name) {
        return String(name || '')
            .trim()
            .replace(/\s+\((?:Sa|[SMTWRF])\s+\d{1,2}(?:\/\d{1,2})?\)\s*$/, '')
            .trim();
    }

    function resolveDetail(name, suggestionLookup, observanceCatalog) {
        var rawName = String(name || '').trim();
        var normalizedName = normalizeLabel(rawName);
        var byId = observanceCatalog && observanceCatalog.by_id ? observanceCatalog.by_id : {};
        var nameLookup = observanceCatalog && observanceCatalog.name_lookup ? observanceCatalog.name_lookup : {};
        var lookup = suggestionLookup && typeof suggestionLookup === 'object' ? suggestionLookup : {};
        var observanceId = '';

        if (rawName !== '' && lookup[rawName]) {
            observanceId = String(lookup[rawName]);
        } else if (normalizedName !== '' && lookup[normalizedName]) {
            observanceId = String(lookup[normalizedName]);
        } else if (normalizedName !== '' && nameLookup[String(normalizedName).toLowerCase()]) {
            observanceId = String(nameLookup[String(normalizedName).toLowerCase()]);
        }

        if (observanceId !== '' && byId[observanceId]) {
            return byId[observanceId];
        }

        return null;
    }

    window.oflcObservanceUi = {
        normalizeLabel: normalizeLabel,
        resolveDetail: resolveDetail
    };
})();
