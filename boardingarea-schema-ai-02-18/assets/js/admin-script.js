jQuery(document).ready(function ($) {
    const $genBtn = $('#basai-generate-btn');
    const $genSaveBtn = $('#basai-generate-save-btn');
    const $typeSelector = $('#basai-type-selector');
    const $reviewSelector = $('#basai-reviewed-selector');
    const $reviewControl = $('.basai-review-control');

    const $statusBar = $('#basai-status-bar');
    const $statusText = $('#basai-status-text');
    const $statusIcon = $statusBar.find('.status-icon-dash');

    const $timestampPill = $('#basai-timestamp-pill');
    const $timestampText = $('#basai-timestamp-text');

    const $editor = $('#basai-json-editor');
    const $insightBox = $('#basai-insight-box');
    const $insightText = $('#basai-insight-text');
    const $insightInput = $('#basai-justification-input');
    const $templateInput = $('#basai-template-id-input');
    const $reviewedInput = $('#basai-reviewed-type-input');
    const $currentLabel = $('#basai-current-type');
    const $loader = $('#basai-loading');

    const $graphSummary = $('#basai-graph-summary');
    const $graphErrors = $('#basai-graph-errors');
    const $graphWrap = $('#basai-graph-wrap');
    const $graphLines = $('#basai-graph-lines');
    const $graphLabels = $('#basai-graph-labels');
    const $graphRows = $('#basai-graph-rows');
    const $graphMode = $('#basai-graph-mode');
    const $summaryMode = $('#basai-summary-mode');
    const $graphTabs = $('.basai-graph-tab');

    let graphTimer = null;
    let graphDrawTimer = null;
    let graphState = null;
    let inspectorData = null;
    let inspectorNodesById = {};
    let inspectorMode = 'graph';

    function updateStatus(msg, type = 'success') {
        $statusBar.removeClass('success error working ready');
        $statusIcon.removeClass('dashicons-yes dashicons-warning dashicons-update dashicons-minus');

        if (type === 'success') {
            $statusBar.addClass('success');
            $statusIcon.addClass('dashicons-yes');
        } else if (type === 'error') {
            $statusBar.addClass('error');
            $statusIcon.addClass('dashicons-warning');
        } else if (type === 'working') {
            $statusBar.addClass('working');
            $statusIcon.addClass('dashicons-update spin-anim');
        } else {
            $statusBar.addClass('ready');
            $statusIcon.addClass('dashicons-minus');
        }

        if (type !== 'working') {
            $statusIcon.removeClass('spin-anim');
        }

        $statusText.text(msg);
    }

    function updateTimestamp(text, hasDate = true) {
        $timestampText.text(text);
        if (hasDate) $timestampPill.addClass('has-date');
        else $timestampPill.removeClass('has-date');
    }

    function toggleReviewSelector() {
        if ($typeSelector.val() === 'Review') $reviewControl.addClass('visible');
        else $reviewControl.removeClass('visible');
    }

    function safeParseJSON(raw) {
        if (!raw) return null;
        try { return JSON.parse(raw); } catch (e) { return null; }
    }

    function prettyPrintJson(raw) {
        const parsed = safeParseJSON(raw);
        if (!parsed) return raw;
        try {
            return JSON.stringify(parsed, null, 2);
        } catch (e) {
            return raw;
        }
    }

    function formatEditorJson() {
        const raw = $editor.val();
        const pretty = prettyPrintJson(raw);
        if (pretty && pretty !== raw) {
            $editor.val(pretty);
        }
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function extractNodes(parsed) {
        if (!parsed || typeof parsed !== 'object') return [];
        if (Array.isArray(parsed['@graph'])) return parsed['@graph'];
        if (parsed['@type']) return [parsed];
        return [];
    }

    function collectIdRefs(value, refs) {
        if (!value) return;
        if (Array.isArray(value)) {
            value.forEach(v => collectIdRefs(v, refs));
            return;
        }
        if (typeof value === 'object') {
            if (typeof value['@id'] === 'string' && value['@id'].trim() !== '') {
                refs.push(value['@id']);
                return;
            }
            Object.keys(value).forEach(k => {
                if (k === '@id' || k === '@type' || k === '@context' || k === '@graph') return;
                collectIdRefs(value[k], refs);
            });
        }
    }

    function extractEdgesFromNode(node, fromId) {
        const edges = [];
        const seen = new Set();

        function walk(value, path, isRoot) {
            if (!value) return;
            if (Array.isArray(value)) {
                value.forEach(v => walk(v, path, false));
                return;
            }
            if (typeof value === 'object') {
                if (typeof value['@id'] === 'string' && value['@id'].trim() !== '') {
                    if (!isRoot) {
                        const prop = path || '@id';
                        const key = `${fromId}||${prop}||${value['@id']}`;
                        if (!seen.has(key)) {
                            seen.add(key);
                            edges.push({ from: fromId, to: value['@id'], prop });
                        }
                        return;
                    }
                }
                Object.keys(value).forEach(k => {
                    if (k === '@id' || k === '@type' || k === '@context' || k === '@graph') return;
                    const nextPath = path ? `${path}.${k}` : k;
                    walk(value[k], nextPath, false);
                });
            }
        }

        walk(node, '', true);
        return edges;
    }

    function extractIdList(value) {
        const out = [];
        function walk(v) {
            if (!v) return;
            if (Array.isArray(v)) {
                v.forEach(walk);
                return;
            }
            if (typeof v === 'object') {
                if (typeof v['@id'] === 'string' && v['@id'].trim() !== '') {
                    out.push(v['@id']);
                    return;
                }
                Object.keys(v).forEach(k => {
                    if (k === '@id' || k === '@type' || k === '@context' || k === '@graph') return;
                    walk(v[k]);
                });
            }
        }
        walk(value);
        return out;
    }

    function getNodeIssues(node, idSet, idCounts) {
        const issues = [];
        const id = typeof node['@id'] === 'string' ? node['@id'] : '';
        if (id && idCounts[id] > 1) {
            issues.push(`Duplicate @id: ${id}`);
        }

        const clone = Object.assign({}, node);
        delete clone['@id'];
        delete clone['@type'];
        delete clone['@context'];
        delete clone['@graph'];
        const refs = [];
        collectIdRefs(clone, refs);
        const unresolved = refs.filter(r => r && !idSet.has(r));
        const unique = Array.from(new Set(unresolved));
        unique.forEach(r => issues.push(`Unresolved @id reference: ${r}`));
        return issues;
    }

    function pluralize(word, count) {
        if (count === 1) return word;
        if (word.endsWith('y') && !/[aeiou]y$/i.test(word)) return word.slice(0, -1) + 'ies';
        if (word.endsWith('s')) return word;
        return word + 's';
    }

    function formatTypeLabel(type, count) {
        const spaced = type.replace(/([a-z0-9])([A-Z])/g, '$1 $2');
        const parts = spaced.split(' ');
        if (parts.length === 0) return type;
        const last = parts[parts.length - 1];
        parts[parts.length - 1] = pluralize(last.toLowerCase(), count);
        const out = parts.join(' ');
        return out.charAt(0).toUpperCase() + out.slice(1);
    }

    function shortenText(str, maxLen = 400) {
        if (!str) return '';
        if (str.length <= maxLen) return str;
        return str.slice(0, maxLen).trim() + '…';
    }

    function getNodeDisplayName(node) {
        const name = node.name || node.headline || node.title || node.url || node['@id'];
        if (typeof name === 'string' && name.trim() !== '') return name.trim();
        return 'Unnamed item';
    }

    function orderKeys(keys) {
        const priority = ['@type', '@id', 'name', 'headline', 'url', 'description'];
        const set = new Set(keys);
        const ordered = [];
        priority.forEach(k => {
            if (set.has(k)) ordered.push(k);
        });
        keys.forEach(k => {
            if (!priority.includes(k)) ordered.push(k);
        });
        return ordered;
    }

    function normalizeDayOfWeek(val) {
        const map = {
            monday: 'http://schema.org/Monday',
            tuesday: 'http://schema.org/Tuesday',
            wednesday: 'http://schema.org/Wednesday',
            thursday: 'http://schema.org/Thursday',
            friday: 'http://schema.org/Friday',
            saturday: 'http://schema.org/Saturday',
            sunday: 'http://schema.org/Sunday'
        };
        if (typeof val !== 'string') return val;
        const key = val.trim().toLowerCase();
        return map[key] || val;
    }

    function normalizeDisplayValue(key, val, mode) {
        if (mode !== 'validator') return val;
        if (key === 'dayOfWeek') {
            if (Array.isArray(val)) return val.map(v => normalizeDayOfWeek(v));
            return normalizeDayOfWeek(val);
        }
        return val;
    }

    function objectSummaryLabel(obj) {
        const type = typeof obj['@type'] === 'string' ? obj['@type'] : '';
        const id = typeof obj['@id'] === 'string' ? obj['@id'] : '';
        if (type && id) return `${type} (${id})`;
        if (type) return type;
        if (id) return id;
        return 'Object';
    }

    function renderObjectDetails(obj, depth, seen, mode) {
        const label = objectSummaryLabel(obj);
        if (mode === 'validator') {
            return `
                <details class="basai-prop-details">
                    <summary class="basai-prop-summary">${escapeHtml(label)}</summary>
                    <div class="basai-prop-nested">${renderProps(obj, depth + 1, seen, mode)}</div>
                </details>
            `;
        }
        return `<div class="basai-prop-nested">${renderProps(obj, depth + 1, seen, mode)}</div>`;
    }

    function renderValue(val, depth, seen, key, mode) {
        const localSeen = seen instanceof Set ? seen : new Set();
        const normalized = normalizeDisplayValue(key, val, mode);
        val = normalized;
        if (val === null || val === undefined || val === '') {
            return '<span class="basai-prop-empty">—</span>';
        }
        if (Array.isArray(val)) {
            if (val.length === 0) return '<span class="basai-prop-empty">—</span>';
            return `<div class="basai-prop-array">${val.map(v => `<div class="basai-prop-array-item">${renderValue(v, depth + 1, localSeen, key, mode)}</div>`).join('')}</div>`;
        }
        if (typeof val === 'object') {
            if (depth >= 5) return '<span class="basai-prop-ellipsis">…</span>';
            const id = typeof val['@id'] === 'string' ? val['@id'] : '';
            const keys = Object.keys(val);
            const idOnly = id && keys.every(k => k === '@id' || k === '@type');
            if (idOnly && inspectorNodesById[id]) {
                if (mode === 'validator' && localSeen.has(id)) {
                    return `<span class="basai-prop-id">${escapeHtml(id)}</span>`;
                }
                if (mode === 'validator') {
                    localSeen.add(id);
                }
                return renderObjectDetails(inspectorNodesById[id], depth, localSeen, mode);
            }
            return renderObjectDetails(val, depth, localSeen, mode);
        }
        const text = shortenText(String(val));
        return escapeHtml(text);
    }

    function renderProps(obj, depth, seen, mode) {
        const keys = Object.keys(obj || {}).filter(k => k !== '@context' && k !== '@graph');
        const ordered = orderKeys(keys);
        return ordered.map(k => {
            return `
                <div class="basai-prop-row">
                    <div class="basai-prop-key">${escapeHtml(k)}</div>
                    <div class="basai-prop-val">${renderValue(obj[k], depth, seen, k, mode)}</div>
                </div>
            `;
        }).join('');
    }

    function renderNodeDetails(node, mode) {
        const seen = new Set();
        const id = typeof node['@id'] === 'string' ? node['@id'] : '';
        if (id) seen.add(id);
        return `<div class="basai-props">${renderProps(node, 0, seen, mode)}</div>`;
    }

    function renderInspectorSummary(data, mode) {
        if (!data) return;
        if (data.error) {
            $summaryMode.html(`<div class="basai-graph-error">${escapeHtml(data.error)}</div>`);
            return;
        }

        const types = (mode === 'rich' && data.rich)
            ? data.rich.categories.slice().sort((a, b) => a.label.localeCompare(b.label))
            : data.types.slice().sort((a, b) => a.label.localeCompare(b.label));

        if (mode === 'validator') {
            const v = data.validator || data;
            const vTypes = v.types || [];
            const totalLine = `${v.errors} ERRORS ${v.warnings} WARNINGS ${v.total} ITEMS`;
            const rows = vTypes.map(t => {
                const warn = t.warnings || 0;
                const countLabel = `${v.errors} ERRORS ${warn} WARNINGS ${t.count} ${t.count === 1 ? 'ITEM' : 'ITEMS'}`;
                const items = (t.items || []).map(item => {
                    const label = getNodeDisplayName(item.node);
                    const issues = item.issues || [];
                    const issueBlock = issues.length
                        ? `<div class="basai-issue-block">
                                <div class="basai-summary-note warn">${issues.length} non-critical issue${issues.length === 1 ? '' : 's'}</div>
                                <ul class="basai-issue-list">${issues.map(i => `<li>${escapeHtml(i)}</li>`).join('')}</ul>
                           </div>`
                        : '';
                    return `
                        <details class="basai-summary-item-card">
                            <summary class="basai-summary-item-summary">
                                <span>${escapeHtml(label)}</span>
                                ${issues.length ? `<span class="basai-summary-count">${issues.length} issue${issues.length === 1 ? '' : 's'}</span>` : ''}
                            </summary>
                            <div class="basai-summary-item-body">
                                ${issueBlock}
                                ${renderNodeDetails(item.node, mode)}
                            </div>
                        </details>
                    `;
                }).join('');
                return `
                    <details class="basai-summary-item">
                        <summary class="basai-validator-row">
                            <span>${escapeHtml(t.raw)}</span>
                            <span class="basai-validator-metrics">${escapeHtml(countLabel)}</span>
                        </summary>
                        <div class="basai-summary-item-body">
                            <div class="basai-summary-section">Detected items</div>
                            <div class="basai-summary-items">${items || '<div class="basai-summary-note">No items found.</div>'}</div>
                        </div>
                    </details>
                `;
            }).join('');

            $summaryMode.html(`
                <div class="basai-summary-title">Detected</div>
                <div class="basai-validator-total">${escapeHtml(totalLine)}</div>
                <div class="basai-summary-list">${rows}</div>
            `);
            return;
        }

        const totalCount = (mode === 'rich' && data.rich) ? data.rich.total : data.total;
        const totalLine = `${totalCount} item${totalCount === 1 ? '' : 's'} detected`;
        const rows = types.map(t => {
            const label = formatTypeLabel(t.raw, t.count);
            const warn = t.warnings || 0;
            const items = (t.items || []).map(item => {
                const label = getNodeDisplayName(item.node);
                const issues = item.issues || [];
                const issueBlock = issues.length
                    ? `<div class="basai-issue-block">
                            <div class="basai-summary-note warn">${issues.length} non-critical issue${issues.length === 1 ? '' : 's'}</div>
                            <ul class="basai-issue-list">${issues.map(i => `<li>${escapeHtml(i)}</li>`).join('')}</ul>
                       </div>`
                    : '';
                return `
                    <details class="basai-summary-item-card">
                        <summary class="basai-summary-item-summary">
                            <span>${escapeHtml(label)}</span>
                            ${issues.length ? `<span class="basai-summary-count">${issues.length} issue${issues.length === 1 ? '' : 's'}</span>` : ''}
                        </summary>
                        <div class="basai-summary-item-body">
                            ${issueBlock}
                            ${renderNodeDetails(item.node, mode)}
                        </div>
                    </details>
                `;
            }).join('');
            return `
                <details class="basai-summary-item">
                    <summary class="basai-summary-type">
                        <span>${escapeHtml(label)}</span>
                        <span class="basai-summary-count">${t.count} ${t.count === 1 ? 'item' : 'items'}</span>
                    </summary>
                    <div class="basai-summary-item-body">
                        ${warn > 0 ? '<div class="basai-summary-note warn">Non-critical issues detected</div>' : ''}
                        <div class="basai-summary-section">Detected items</div>
                        <div class="basai-summary-items">${items || '<div class="basai-summary-note">No items found.</div>'}</div>
                    </div>
                </details>
            `;
        }).join('');

        const title = (mode === 'rich') ? 'Test results' : 'Inspector Summary';
        const sub = (mode === 'rich')
            ? 'Valid items are eligible for Google Search\'s rich results.'
            : 'Internal heuristic summary (not an official validator)';
        const metric = (mode === 'rich')
            ? `<span class="dashicons dashicons-yes"></span> ${escapeHtml(totalLine)}`
            : escapeHtml(totalLine);

        $summaryMode.html(`
            <div class="basai-summary-title">${escapeHtml(title)}</div>
            <div class="basai-summary-sub">${escapeHtml(sub)}</div>
            <div class="basai-summary-metric">${metric}</div>
            <div class="basai-summary-section">Detected structured data</div>
            <div class="basai-summary-list">${rows}</div>
        `);
    }

    function getSemanticCategory(typeName) {
        const t = Array.isArray(typeName) ? typeName[0] : typeName;
        if (!t) return 'thing';

        if (['Person', 'Organization', 'LocalBusiness', 'Corporation', 'SportsTeam'].includes(t)) return 'agent';
        if (['WebPage', 'Article', 'BlogPosting', 'NewsArticle', 'Review', 'CreativeWork', 'Comment'].includes(t)) return 'creative';
        if (['BreadcrumbList', 'ItemList', 'ListItem', 'WebSite'].includes(t)) return 'struct';
        return 'thing';
    }

    function drawGraphLines() {
        if (!graphState || !$graphWrap.length) return;
        const wrapEl = $graphWrap[0];
        const linesSvg = $graphLines[0];
        const labelsSvg = $graphLabels[0];
        if (!wrapEl || !linesSvg) return;

        const wrapRect = wrapEl.getBoundingClientRect();
        let width = Math.max(wrapEl.scrollWidth, wrapRect.width);
        let height = Math.max(wrapEl.scrollHeight, wrapRect.height);
        if (width === 0 || height === 0) return;

        const accent = graphState.accent || (graphState.accent = (getComputedStyle(wrapEl).getPropertyValue('--basai-graph-accent') || '').trim() || '#4a6fa5');
        const lineColor = (getComputedStyle(wrapEl).getPropertyValue('--basai-graph-line') || '').trim() || accent;
        const defs = `<defs><marker id="basai-arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto" markerUnits="strokeWidth"><path d="M0,0 L8,4 L0,8 Z" fill="${lineColor}"></path></marker></defs>`;
        const lines = [];
        const labels = [];

        const nodeRectsById = {};
        const nodeRects = Object.entries(graphState.nodeEls).map(([id, el]) => {
            const rect = el.getBoundingClientRect();
            const box = {
                left: rect.left - wrapRect.left + wrapEl.scrollLeft,
                right: rect.right - wrapRect.left + wrapEl.scrollLeft,
                top: rect.top - wrapRect.top + wrapEl.scrollTop,
                bottom: rect.bottom - wrapRect.top + wrapEl.scrollTop
            };
            nodeRectsById[id] = box;
            return box;
        });

        const minX = nodeRects.reduce((min, r) => Math.min(min, r.left), width);
        const maxX = nodeRects.reduce((max, r) => Math.max(max, r.right), 0);
        const graphCenterX = (minX + maxX) / 2;

        const rowEls = Array.from($graphRows[0]?.querySelectorAll('.basai-graph-row') || []);
        const rowRects = rowEls.map(el => {
            const rect = el.getBoundingClientRect();
            return {
                top: rect.top - wrapRect.top + wrapEl.scrollTop,
                bottom: rect.bottom - wrapRect.top + wrapEl.scrollTop
            };
        });

        const rowLabelRects = Array.from($graphRows[0]?.querySelectorAll('.basai-graph-row-label') || []).map(el => {
            const rect = el.getBoundingClientRect();
            return {
                left: rect.left - wrapRect.left + wrapEl.scrollLeft,
                right: rect.right - wrapRect.left + wrapEl.scrollLeft,
                top: rect.top - wrapRect.top + wrapEl.scrollTop,
                bottom: rect.bottom - wrapRect.top + wrapEl.scrollTop
            };
        });

        const labelObstacles = nodeRects.concat(rowLabelRects);

        linesSvg.setAttribute('width', width);
        linesSvg.setAttribute('height', height);
        if (labelsSvg) {
            labelsSvg.setAttribute('width', width);
            labelsSvg.setAttribute('height', height);
        }


        function pointHitsNode(x, y) {
            return labelObstacles.some(rect => (
                x >= rect.left - 6 &&
                x <= rect.right + 6 &&
                y >= rect.top - 6 &&
                y <= rect.bottom + 6
            ));
        }

        function estimateLabelBox(x, y, text) {
            const charWidth = 6.4;
            const baseWidth = Math.max(24, text.length * charWidth);
            const pad = 8;
            const width = baseWidth + pad;
            const height = 14;
            return {
                left: x - width / 2,
                right: x + width / 2,
                top: y - height,
                bottom: y + 4
            };
        }

        function labelOverlapsObstacle(x, y, text) {
            const box = estimateLabelBox(x, y, text);
            const margin = 6;
            return labelObstacles.some(rect => !(
                box.right < rect.left - margin ||
                box.left > rect.right + margin ||
                box.bottom < rect.top - margin ||
                box.top > rect.bottom + margin
            ));
        }

        const labelSlots = new Set();
        const labelBoxes = [];
        const labelCellSize = 18;
        function slotKey(x, y) {
            return `${Math.round(x / labelCellSize)},${Math.round(y / labelCellSize)}`;
        }
        function isSlotFree(x, y) {
            return !labelSlots.has(slotKey(x, y));
        }
        function reserveSlot(x, y) {
            labelSlots.add(slotKey(x, y));
        }

        function boxesOverlap(a, b, margin = 4) {
            return !(
                a.right < b.left - margin ||
                a.left > b.right + margin ||
                a.bottom < b.top - margin ||
                a.top > b.bottom + margin
            );
        }

        function labelOverlapsLabels(box) {
            return labelBoxes.some(existing => boxesOverlap(box, existing, 4));
        }

        function labelIsFree(x, y, text) {
            if (labelOverlapsObstacle(x, y, text)) return false;
            const box = estimateLabelBox(x, y, text);
            if (labelOverlapsLabels(box)) return false;
            if (!isSlotFree(x, y)) return false;
            return true;
        }

        function reserveLabel(x, y, text) {
            reserveSlot(x, y);
            labelBoxes.push(estimateLabelBox(x, y, text));
        }

        function cubicTangent(t, p0, p1, p2, p3) {
            const mt = 1 - t;
            const dx = 3 * mt * mt * (p1.x - p0.x) +
                6 * mt * t * (p2.x - p1.x) +
                3 * t * t * (p3.x - p2.x);
            const dy = 3 * mt * mt * (p1.y - p0.y) +
                6 * mt * t * (p2.y - p1.y) +
                3 * t * t * (p3.y - p2.y);
            const len = Math.hypot(dx, dy) || 1;
            return { x: dx / len, y: dy / len };
        }

        function findLabelPoint(curve, text, bias) {
            const baseTs = [0.25, 0.35, 0.45, 0.55, 0.65, 0.75, 0.2, 0.8];
            const ts = [];
            baseTs.forEach(t => {
                const v = Math.min(0.85, Math.max(0.15, t + bias));
                if (!ts.includes(v)) ts.push(v);
            });
            const offsets = [0, 8, -8, 16, -16, 24, -24, 32, -32, 40, -40];
            const tShifts = [0, 8, -8, 16, -16];

            for (const t of ts) {
                const p = cubicPoint(t, curve.p0, curve.p1, curve.p2, curve.p3);
                const tangent = cubicTangent(t, curve.p0, curve.p1, curve.p2, curve.p3);
                const normal = { x: -tangent.y, y: tangent.x };
                for (const off of offsets) {
                    for (const shift of tShifts) {
                        const nx = p.x + normal.x * off + tangent.x * shift;
                        const ny = p.y + normal.y * off + tangent.y * shift;
                        if (!labelIsFree(nx, ny, text)) continue;
                        reserveLabel(nx, ny, text);
                        return { x: nx, y: ny };
                    }
                }
            }
            return null;
        }

        function cubicPoint(t, p0, p1, p2, p3) {
            const mt = 1 - t;
            const mt2 = mt * mt;
            const t2 = t * t;
            const a = mt2 * mt;
            const b = 3 * mt2 * t;
            const c = 3 * mt * t2;
            const d = t * t2;
            return {
                x: a * p0.x + b * p1.x + c * p2.x + d * p3.x,
                y: a * p0.y + b * p1.y + c * p2.y + d * p3.y
            };
        }

        function pointHitsRect(point, rect) {
            return (
                point.x >= rect.left - 4 &&
                point.x <= rect.right + 4 &&
                point.y >= rect.top - 4 &&
                point.y <= rect.bottom + 4
            );
        }

        function curveHitsObstacles(curve, obstacles) {
            const samples = [0.2, 0.4, 0.6, 0.8].map(t => cubicPoint(t, curve.p0, curve.p1, curve.p2, curve.p3));
            return samples.some(pt => obstacles.some(rect => pointHitsRect(pt, rect)));
        }

        function buildCurve(x1, y1, x2, y2, offsetX) {
            const verticalGap = y2 - y1;
            const controlOffset = Math.min(160, Math.max(60, verticalGap * 0.5));
            const c1 = { x: x1 + offsetX, y: y1 + controlOffset };
            const c2 = { x: x2 + offsetX, y: y2 - controlOffset };
            const p0 = { x: x1, y: y1 };
            const p3 = { x: x2, y: y2 };
            const d = `M ${x1} ${y1} C ${c1.x} ${c1.y} ${c2.x} ${c2.y} ${x2} ${y2}`;
            return { d, p0, p1: c1, p2: c2, p3 };
        }

        const edgeGroups = {};
        graphState.lines.forEach(edge => {
            const key = `${edge.from}||${edge.to}`;
            if (!edgeGroups[key]) edgeGroups[key] = [];
            edgeGroups[key].push(edge);
        });

        Object.values(edgeGroups).forEach(group => {
            const count = group.length;
            group.forEach((edge, idx) => {
            const fromEl = graphState.nodeEls[edge.from];
            const toEl = graphState.nodeEls[edge.to];
            if (!fromEl || !toEl) return;

            const fromRect = fromEl.getBoundingClientRect();
            const toRect = toEl.getBoundingClientRect();

            const baseX1 = fromRect.left + fromRect.width / 2 - wrapRect.left + wrapEl.scrollLeft;
            const baseY1 = fromRect.bottom - wrapRect.top + wrapEl.scrollTop + 4;
            const baseX2 = toRect.left + toRect.width / 2 - wrapRect.left + wrapEl.scrollLeft;
            const baseY2 = toRect.top - wrapRect.top + wrapEl.scrollTop - 4;

            const spread = (idx - (count - 1) / 2) * 14;
            const x1 = baseX1 + spread;
            const y1 = baseY1;
            const x2 = baseX2 + spread;
            const y2 = baseY2;

            const obstacles = nodeRects.filter(rect => rect !== nodeRectsById[edge.from] && rect !== nodeRectsById[edge.to]);
            const baseCurve = buildCurve(x1, y1, x2, y2, 0);
            let curve = baseCurve;
            if (curveHitsObstacles(baseCurve, obstacles)) {
                const dir = ((x1 + x2) / 2) < graphCenterX ? -1 : 1;
                const bow = 36 + Math.min(40, Math.abs(spread));
                const tryCurve = buildCurve(x1, y1, x2, y2, dir * bow);
                if (!curveHitsObstacles(tryCurve, obstacles)) {
                    curve = tryCurve;
                } else {
                    const altCurve = buildCurve(x1, y1, x2, y2, -dir * bow);
                    if (!curveHitsObstacles(altCurve, obstacles)) {
                        curve = altCurve;
                    } else {
                        curve = tryCurve;
                    }
                }
            }

            lines.push(`<path d="${curve.d}" stroke="${lineColor}" stroke-width="1.2" fill="none" marker-end="url(#basai-arrow)" />`);

            if (edge.label) {
                const raw = edge.label;
                const text = raw.length > 25 ? raw.slice(0, 23) + '…' : raw;
                const bias = (idx - (count - 1) / 2) * 0.04;
                let placed = findLabelPoint(curve, text, bias);
                if (!placed && bias !== 0) {
                    placed = findLabelPoint(curve, text, 0);
                }
                if (placed) {
                    labels.push(
                        `<text x="${placed.x}" y="${placed.y}" text-anchor="middle">
                            <title>${escapeHtml(raw)}</title>
                            ${escapeHtml(text)}
                        </text>`
                    );
                }
            }
            });
        });

        linesSvg.innerHTML = defs + lines.join('');
        if (labelsSvg) {
            labelsSvg.innerHTML = labels.join('');
        }
    }

    function scheduleGraphDraw() {
        clearTimeout(graphDrawTimer);
        graphDrawTimer = setTimeout(drawGraphLines, 80);
        setTimeout(drawGraphLines, 220);
    }

    function renderGraph(raw) {
        $graphSummary.empty();
        $graphErrors.empty();
        $graphRows.empty();
        if ($graphLines.length) $graphLines.empty();
        if ($graphLabels.length) $graphLabels.empty();
        inspectorData = null;
        inspectorNodesById = {};

        const parsed = safeParseJSON(raw);
        if (!parsed) {
            $graphErrors.html('<div class="basai-graph-error">Invalid JSON: unable to parse.</div>');
            graphState = null;
            inspectorData = { error: 'Invalid JSON: unable to parse.' };
            renderInspectorSummary(inspectorData, inspectorMode);
            return;
        }

        const nodes = extractNodes(parsed);
        if (nodes.length === 0) {
            $graphErrors.html('<div class="basai-graph-error">No schema nodes found.</div>');
            graphState = null;
            inspectorData = { error: 'No schema nodes found.' };
            renderInspectorSummary(inspectorData, inspectorMode);
            return;
        }

        const idMap = {};
        const idSet = new Set();
        const idCounts = {};
        const typeCounts = {};

        nodes.forEach(n => {
            const id = typeof n['@id'] === 'string' ? n['@id'] : '';
            if (id) {
                idSet.add(id);
                idCounts[id] = (idCounts[id] || 0) + 1;
                if (!idMap[id]) idMap[id] = n;
            }
            const t = n['@type'];
            let types = Array.isArray(t) ? t : (t ? [t] : []);
            if (types.length === 0) types = ['Unknown'];
            types.forEach(tp => {
                typeCounts[tp] = (typeCounts[tp] || 0) + 1;
            });
        });
        inspectorNodesById = idMap;

        const refs = [];
        nodes.forEach(n => {
            const clone = Object.assign({}, n);
            delete clone['@id'];
            delete clone['@type'];
            collectIdRefs(clone, refs);
        });
        const unresolved = refs.filter(r => r && !idSet.has(r));
        const dupes = Object.keys(idCounts).filter(id => idCounts[id] > 1);

        const summary = `Nodes: ${nodes.length} • Types: ${Object.keys(typeCounts).length} • ID refs: ${refs.length} • Unresolved: ${unresolved.length} • Duplicate IDs: ${dupes.length}`;
        $graphSummary.text(summary);

        if (unresolved.length > 0) {
            const uniq = Array.from(new Set(unresolved));
            $graphErrors.append(`<div class="basai-graph-warn">Unresolved @id references (${uniq.length}): ${escapeHtml(uniq.slice(0, 6).join(', '))}${uniq.length > 6 ? '…' : ''}</div>`);
        }
        if (dupes.length > 0) {
            $graphErrors.append(`<div class="basai-graph-warn">Duplicate @id nodes (${dupes.length}): ${escapeHtml(dupes.slice(0, 6).join(', '))}${dupes.length > 6 ? '…' : ''}</div>`);
        }

        const edges = [];
        const edgesByFrom = {};
        nodes.forEach(n => {
            const fromId = typeof n['@id'] === 'string' ? n['@id'] : '';
            if (!fromId) return;
            const out = extractEdgesFromNode(n, fromId);
            if (out.length) {
                edgesByFrom[fromId] = out;
                edges.push(...out);
            }
        });

        const inbound = {};
        edges.forEach(e => {
            if (idSet.has(e.to)) {
                inbound[e.to] = (inbound[e.to] || 0) + 1;
            }
        });

        const webPageIds = Object.keys(idMap).filter(id => {
            const t = idMap[id]['@type'];
            const types = Array.isArray(t) ? t : (t ? [t] : []);
            return types.includes('WebPage');
        });
        let rootIds = Object.keys(idMap).filter(id => (inbound[id] || 0) === 0);
        if (webPageIds.length > 0) {
            rootIds = Array.from(new Set([ ...webPageIds, ...rootIds ]));
        }
        if (rootIds.length === 0) {
            rootIds = Object.keys(idMap);
        }

        const primaryIds = new Set();
        webPageIds.forEach(id => {
            const mainEntity = idMap[id]['mainEntity'];
            extractIdList(mainEntity).forEach(pid => primaryIds.add(pid));
        });

        if (primaryIds.size === 0 && webPageIds.length) {
            edges.forEach(e => {
                if (e.prop === 'mainEntityOfPage' && webPageIds.includes(e.to)) {
                    primaryIds.add(e.from);
                }
            });
        }

        if (primaryIds.size === 0) {
            const fallbackTypes = ['BlogPosting','Article','NewsArticle','Review','HowTo','FAQPage','ItemList','VideoObject','Product','Trip','Place','Airline','JobPosting','CollectionPage'];
            for (const id of Object.keys(idMap)) {
                const t = idMap[id]['@type'];
                const types = Array.isArray(t) ? t : (t ? [t] : []);
                if (types.some(tp => fallbackTypes.includes(tp))) {
                    primaryIds.add(id);
                    break;
                }
            }
        }

        const adjacency = {};
        edges.forEach(e => {
            if (!idSet.has(e.from) || !idSet.has(e.to)) return;
            if (!adjacency[e.from]) adjacency[e.from] = [];
            adjacency[e.from].push(e.to);
        });

        const distance = {};
        const queue = [];
        rootIds.forEach(id => {
            distance[id] = 0;
            queue.push(id);
        });

        while (queue.length) {
            const current = queue.shift();
            const nexts = adjacency[current] || [];
            nexts.forEach(nid => {
                if (distance[nid] === undefined) {
                    distance[nid] = distance[current] + 1;
                    queue.push(nid);
                }
            });
        }

        const orphans = [];
        const connectedByLevel = {};
        Object.keys(idMap).forEach(id => {
            if (distance[id] === undefined) {
                orphans.push(id);
                return;
            }
            const level = distance[id];
            if (!connectedByLevel[level]) connectedByLevel[level] = [];
            connectedByLevel[level].push(id);
        });

        const nodeEls = {};

        function buildNodeCard(id, badges) {
            const node = idMap[id] || {};
            const t = node['@type'];
            const mainType = Array.isArray(t) ? t[0] : (t || 'Thing');
            const typeLabel = Array.isArray(t) ? t.join(', ') : (t || 'Thing');
            const semanticCat = getSemanticCategory(mainType);

            let niceName = node.name || node.headline || node.alternativeHeadline || node.caption || node.description || '';
            if (!niceName) {
                if (mainType === 'Review' && node.author && node.author.name) {
                    niceName = `Review by ${node.author.name}`;
                } else if (mainType === 'BreadcrumbList') {
                    niceName = 'Navigation Path';
                } else {
                    niceName = `Untitled ${mainType}`;
                }
            }

            const rawId = node['@id'] || '(no @id)';
            const shortId = rawId.length > 35 ? `...${rawId.slice(-30)}` : rawId;

            const inboundCount = inbound[id] || 0;
            const edgesOut = edgesByFrom[id] || [];

            const $card = $(`<div class="basai-graph-node" data-semantic="${escapeHtml(semanticCat)}"></div>`);
            if (node['@id']) {
                $card.attr('data-node-id', node['@id']);
                nodeEls[node['@id']] = $card[0];
            }

            const $header = $('<div class="basai-node-header"></div>');
            const displayType = typeLabel.length > 20 ? `${typeLabel.substring(0, 18)}..` : typeLabel;
            $header.append(`<span class="basai-graph-node-title" title="${escapeHtml(typeLabel)}">${escapeHtml(displayType)}</span>`);

            if (badges.length > 0) {
                const $badgeWrap = $('<div class="basai-graph-node-badges"></div>');
                badges.forEach(b => {
                    if (b !== 'Connected') {
                        $badgeWrap.append(`<span class="basai-graph-badge ${b.toLowerCase()}">${escapeHtml(b)}</span>`);
                    }
                });
                if ($badgeWrap.children().length > 0) {
                    $header.append($badgeWrap);
                }
            }

            const $body = $('<div class="basai-node-body"></div>');
            $body.append(`<div class="basai-node-name" title="${escapeHtml(niceName)}">${escapeHtml(niceName)}</div>`);

            const $footer = $('<div class="basai-node-footer"></div>');
            $footer.append(`<div class="full-id-wrap" title="${escapeHtml(rawId)}">${escapeHtml(shortId)}</div>`);

            const $metrics = $('<div class="metrics-wrap"></div>');
            $metrics.append(`<span class="metric-item"><span class="dashicons dashicons-arrow-down-alt2"></span> ${inboundCount} In</span>`);
            $metrics.append(`<span class="metric-item"><span class="dashicons dashicons-arrow-up-alt2"></span> ${edgesOut.length} Out</span>`);
            $footer.append($metrics);

            $card.append($header);
            $card.append($body);
            $card.append($footer);
            return $card;
        }

        let rowIndex = 0;

        function appendRow(label, ids, badgeResolver) {
            if (!ids || ids.length === 0) return;
            const $row = $('<div class="basai-graph-row"></div>');
            $row.attr('data-row-index', String(rowIndex));
            rowIndex += 1;
            $row.append(`<div class="basai-graph-row-label">${escapeHtml(label)}</div>`);
            const $cards = $('<div class="basai-graph-row-cards"></div>');
            ids.forEach(id => {
                const badges = badgeResolver(id);
                $cards.append(buildNodeCard(id, badges));
            });
            $row.append($cards);
            $graphRows.append($row);
        }

        const rootSet = new Set(rootIds);
        const primarySet = new Set(Array.from(primaryIds).filter(id => idSet.has(id)));
        const orphanSet = new Set(orphans);

        appendRow('Roots', rootIds, id => {
            const badges = ['Root'];
            if (primarySet.has(id)) badges.push('Primary');
            return badges;
        });

        const primaryOnly = Array.from(primarySet).filter(id => !rootSet.has(id));
        appendRow('Primary', primaryOnly, () => ['Primary']);

        const maxLevel = Math.max(...Object.keys(connectedByLevel).map(n => parseInt(n, 10)));
        for (let level = 1; level <= maxLevel; level++) {
            const ids = (connectedByLevel[level] || []).filter(id => !rootSet.has(id) && !primarySet.has(id) && !orphanSet.has(id));
            if (ids.length) {
                appendRow(`Connected Level ${level}`, ids, () => ['Connected']);
            }
        }

        appendRow('Orphans', orphans, () => ['Orphan']);

        const lineMap = {};
        edges.forEach(e => {
            if (!idSet.has(e.from) || !idSet.has(e.to)) return;
            const key = `${e.from}||${e.to}`;
            if (!lineMap[key]) {
                lineMap[key] = { from: e.from, to: e.to, props: new Set() };
            }
            lineMap[key].props.add(e.prop);
        });

        const lineEdges = Object.values(lineMap).map(row => ({
            from: row.from,
            to: row.to,
            label: Array.from(row.props).join(', ')
        }));

        graphState = { nodeEls, lines: lineEdges };
        scheduleGraphDraw();

        const typeStats = {};
        let warningsTotal = 0;
        nodes.forEach(n => {
            const t = n['@type'];
            let types = Array.isArray(t) ? t : (t ? [t] : []);
            if (types.length === 0) types = ['Unknown'];
            const issues = getNodeIssues(n, idSet, idCounts);
            const hasWarning = issues.length > 0;
            if (hasWarning) warningsTotal += 1;
            types.forEach(tp => {
                if (!typeStats[tp]) typeStats[tp] = { raw: tp, count: 0, warnings: 0, label: tp, items: [] };
                typeStats[tp].count += 1;
                if (hasWarning) typeStats[tp].warnings += 1;
                typeStats[tp].items.push({ node: n, issues });
            });
        });

        const richMap = {
            Article: 'Articles',
            BlogPosting: 'Articles',
            NewsArticle: 'Articles',
            BreadcrumbList: 'Breadcrumbs',
            Review: 'Review snippets',
            LocalBusiness: 'Local businesses',
            Product: 'Products',
            FAQPage: 'FAQ',
            HowTo: 'How-to',
            VideoObject: 'Videos',
            Recipe: 'Recipes',
            Event: 'Events',
            JobPosting: 'Job postings'
        };
        const richOrder = ['Articles','Breadcrumbs','Local businesses','Organization','Review snippets','Products','FAQ','How-to','Videos','Recipes','Events','Job postings'];
        const richStats = {};
        Object.values(typeStats).forEach(stat => {
            const group = richMap[stat.raw];
            if (!group) return;
            if (!richStats[group]) richStats[group] = { raw: group, label: group, count: 0, warnings: 0, items: [] };
            richStats[group].count += stat.count;
            richStats[group].warnings += stat.warnings;
            richStats[group].items.push(...stat.items);
        });
        const richCategories = Object.values(richStats).sort((a, b) => {
            const ai = richOrder.indexOf(a.label);
            const bi = richOrder.indexOf(b.label);
            if (ai === -1 && bi === -1) return a.label.localeCompare(b.label);
            if (ai === -1) return 1;
            if (bi === -1) return -1;
            return ai - bi;
        });
        const richTotal = richCategories.reduce((sum, c) => sum + (c.count || 0), 0);

        const supportProps = new Set(['publisher', 'isPartOf', 'mainEntityOfPage']);
        const inboundProps = {};
        edges.forEach(e => {
            const to = e.to;
            if (!to) return;
            const prop = String(e.prop || '').split('.')[0];
            if (!inboundProps[to]) inboundProps[to] = new Set();
            inboundProps[to].add(prop);
        });

        const isValidatorRoot = (node) => {
            const id = typeof node['@id'] === 'string' ? node['@id'] : '';
            if (!id) return true;
            const props = inboundProps[id];
            if (!props) return true;
            for (const p of props) {
                if (!supportProps.has(p)) return false;
            }
            return true;
        };

        const rootNodes = nodes.filter(isValidatorRoot);
        const validatorTypeStats = {};
        let validatorWarnings = 0;
        rootNodes.forEach(n => {
            const t = n['@type'];
            let types = Array.isArray(t) ? t : (t ? [t] : []);
            if (types.length === 0) types = ['Unknown'];
            const issues = getNodeIssues(n, idSet, idCounts);
            if (issues.length > 0) validatorWarnings += 1;
            types.forEach(tp => {
                if (!validatorTypeStats[tp]) validatorTypeStats[tp] = { raw: tp, count: 0, warnings: 0, label: tp, items: [] };
                validatorTypeStats[tp].count += 1;
                if (issues.length > 0) validatorTypeStats[tp].warnings += 1;
                validatorTypeStats[tp].items.push({ node: n, issues });
            });
        });

        inspectorData = {
            total: nodes.length,
            errors: 0,
            warnings: warningsTotal,
            types: Object.values(typeStats),
            validator: {
                total: rootNodes.length,
                errors: 0,
                warnings: validatorWarnings,
                types: Object.values(validatorTypeStats)
            },
            rich: {
                total: richTotal,
                categories: richCategories
            }
        };
        renderInspectorSummary(inspectorData, inspectorMode);
    }

    function formatIssue(issue) {
        if (!issue) return '';
        if (typeof issue === 'string') return issue;
        if (issue.message) return issue.message;
        try { return JSON.stringify(issue); } catch (e) { return String(issue); }
    }

    function renderServerValidation(report) {
        if (!report) return;
        const errors = Array.isArray(report.errors) ? report.errors : [];
        const warnings = Array.isArray(report.warnings) ? report.warnings : [];

        if (!errors.length && !warnings.length) {
            $graphErrors.append('<div class="basai-graph-ok">Server validation: no issues found.</div>');
            return;
        }

        if (errors.length) {
            const items = errors.slice(0, 5).map(issue => `<li>${escapeHtml(formatIssue(issue))}</li>`).join('');
            $graphErrors.append(`<div class="basai-graph-error"><strong>Server validation errors (${errors.length})</strong>${items ? `<ul class="basai-graph-list">${items}</ul>` : ''}</div>`);
        }
        if (warnings.length) {
            const items = warnings.slice(0, 5).map(issue => `<li>${escapeHtml(formatIssue(issue))}</li>`).join('');
            $graphErrors.append(`<div class="basai-graph-warn"><strong>Server validation warnings (${warnings.length})</strong>${items ? `<ul class="basai-graph-list">${items}</ul>` : ''}</div>`);
        }
    }

    function setCollapsibleState($card, collapsed) {
        $card.toggleClass('is-collapsed', collapsed);
        const $toggle = $card.find('.basai-collapse-toggle').first();
        if ($toggle.length) {
            $toggle.attr('aria-expanded', collapsed ? 'false' : 'true');
        }
        if (!collapsed && $card.is('#basai-graph-card')) {
            renderGraph($editor.val());
        }
    }

    function toggleCollapsible($card) {
        setCollapsibleState($card, !$card.hasClass('is-collapsed'));
    }

    $typeSelector.on('change', toggleReviewSelector);
    toggleReviewSelector();

    formatEditorJson();
    renderGraph($editor.val());

    $graphTabs.on('click', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const mode = $btn.data('mode');
        if (!mode || mode === inspectorMode) return;

        inspectorMode = mode;
        $graphTabs.removeClass('is-active').attr('aria-selected', 'false');
        $btn.addClass('is-active').attr('aria-selected', 'true');

        if (mode === 'graph') {
            $summaryMode.addClass('basai-hidden');
            $graphMode.removeClass('basai-hidden');
            renderGraph($editor.val());
        } else {
            $graphMode.addClass('basai-hidden');
            $summaryMode.removeClass('basai-hidden');
            renderInspectorSummary(inspectorData, mode);
        }
    });

    $('.basai-collapse-toggle').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $card = $(this).closest('.basai-collapsible');
        if ($card.length) toggleCollapsible($card);
    });

    $('.basai-collapsible-header').on('click', function (e) {
        if ($(e.target).closest('button, a, input, select, textarea, label').length) return;
        const $card = $(this).closest('.basai-collapsible');
        if ($card.length) toggleCollapsible($card);
    });

    function runGenerate(save = false) {
        const selectedType = $typeSelector.val();
        const selectedReviewed = $reviewSelector.val();
        const isAuto = (selectedType === 'Auto');
        const typeLabel = $typeSelector.find('option:selected').text().trim();
        const workingMsg = isAuto ? 'AI Detecting & Building...' : 'Building ' + typeLabel + '...';

        $genBtn.prop('disabled', true);
        $genSaveBtn.prop('disabled', true);
        $loader.addClass('visible');
        updateStatus(workingMsg, 'working');

        $.ajax({
            url: basaiData.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'basai_generate_now',
                nonce: basaiData.nonce,
                post_id: $('#basai-post-id').val(),
                selected_type: selectedType,
                selected_reviewed_type: selectedReviewed,
                save: save ? 1 : 0
            },
            success: function (res) {
                if (res.success) {
                    const formatted = prettyPrintJson(res.data.schema || '');
                    $editor.val(formatted || res.data.schema);
                    renderGraph($editor.val());

                    $templateInput.val(res.data.generated_type);
                    if (res.data.generated_type && $typeSelector.find(`option[value="${res.data.generated_type}"]`).length > 0) {
                        $typeSelector.val(res.data.generated_type);
                    }

                    if (res.data.reviewed_type !== undefined) {
                        $reviewSelector.val(res.data.reviewed_type);
                        $reviewedInput.val(res.data.reviewed_type);
                    }

                    toggleReviewSelector();

                    $currentLabel.text(res.data.generated_label || res.data.generated_type);

                    $insightText.text(res.data.justification || '');
                    $insightInput.val(res.data.justification || '');
                    if (res.data.justification) $insightBox.removeClass('hidden');
                    else $insightBox.addClass('hidden');

                    updateStatus(save && res.data.saved ? '✔ Built & Saved' : '✔ Built Successfully', 'success');
                    updateTimestamp('Generated: Just now', true);
                } else {
                    updateStatus('Error: ' + (res.data && res.data.message ? res.data.message : 'Unknown error'), 'error');
                }
            },
            error: function () {
                updateStatus('Server Error', 'error');
            },
            complete: function () {
                $genBtn.prop('disabled', false);
                $genSaveBtn.prop('disabled', false);
                $loader.removeClass('visible');
            }
        });
    }

    $genBtn.on('click', function (e) {
        e.preventDefault();
        runGenerate(false);
    });

    $genSaveBtn.on('click', function (e) {
        e.preventDefault();
        runGenerate(true);
    });

    $('#basai-validate-btn').on('click', function (e) {
        e.preventDefault();
        const json = $editor.val().trim();
        if (!json) { 
            updateStatus('Editor is empty', 'error'); 
            return; 
        }
        try {
            JSON.parse(json);
            updateStatus('Validating...', 'working');
        } catch (err) {
            updateStatus('Invalid Syntax', 'error');
            alert('JSON Error: ' + err.message);
            return;
        }

        $.ajax({
            url: basaiData.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'basai_validate_schema',
                nonce: basaiData.nonce,
                post_id: $('#basai-post-id').val(),
                json: json
            },
            success: function (res) {
                if (res.success) {
                    renderGraph($editor.val());
                    renderServerValidation(res.data || {});
                    const errs = res.data && res.data.errors ? res.data.errors.length : 0;
                    const warns = res.data && res.data.warnings ? res.data.warnings.length : 0;
                    const msg = errs > 0
                        ? `Validation: ${errs} errors, ${warns} warnings`
                        : (warns > 0 ? `Validation: 0 errors, ${warns} warnings` : 'Validation: no issues');
                    updateStatus(msg, errs > 0 ? 'error' : 'success');
                } else {
                    const msg = res.data && res.data.message ? res.data.message : 'Validation failed';
                    updateStatus(msg, 'error');
                }
            },
            error: function () {
                updateStatus('Server Error', 'error');
            }
        });
    });

    $('#basai-test-validator-btn').on('click', function (e) {
        e.preventDefault();
        const json = $editor.val().trim();
        if (!json) {
            updateStatus('Nothing to test', 'error');
            return;
        }
        navigator.clipboard.writeText(json).then(() => {
            updateStatus('✔ Copied to Clipboard', 'success');
            setTimeout(() => {
                window.open('https://validator.schema.org/', '_blank');
            }, 500);
        }).catch(err => {
            updateStatus('Copy Failed', 'error');
            console.error('Clipboard error:', err);
        });
    });

    $editor.on('input', function () {
        clearTimeout(graphTimer);
        graphTimer = setTimeout(() => {
            renderGraph($editor.val());
        }, 200);
    });

    $(window).on('resize', function () {
        scheduleGraphDraw();
    });
});
