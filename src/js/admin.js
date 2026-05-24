import '../scss/admin.scss';

(function($) {
	'use strict';

	if (!$) {
		return;
	}

	var templateEditor = null;
	var wrapperBeforeEditor = null;
	var wrapperAfterEditor = null;
	var styleCodeEditor = null;
	var scriptCodeEditor = null;
	var cfg = window.dataImporterAdmin || {};
	var i18n = cfg.i18n || {};

	// ------------------------------------------------------------------
	// CodeMirror (PHP/HTML): main template + before/after list wrappers
	// ------------------------------------------------------------------

	function getCodeEditorSettings(language) {
		var editors = cfg.codeEditors || {};
		return editors[language] || cfg.codeEditor || null;
	}

	function initOneCodeEditor(textareaId, settings, assignCm) {
		var field = document.getElementById(textareaId);
		if (!field || !window.wp || !wp.codeEditor || !settings) {
			return;
		}

		var result   = wp.codeEditor.initialize(field, settings);

		if (result && result.codemirror && typeof assignCm === 'function') {
			assignCm(result.codemirror);
		}
	}

	function initCodeEditor() {
		if (!window.wp || !wp.codeEditor) {
			return;
		}

		var phpSettings = getCodeEditorSettings('php');
		var cssSettings = getCodeEditorSettings('css');
		var jsSettings = getCodeEditorSettings('javascript');

		initOneCodeEditor('data_importer_template_html', phpSettings, function(cm) {
			templateEditor = cm;
		});
		initOneCodeEditor('data_importer_wrapper_before', phpSettings, function(cm) {
			wrapperBeforeEditor = cm;
		});
		initOneCodeEditor('data_importer_wrapper_after', phpSettings, function(cm) {
			wrapperAfterEditor = cm;
		});
		initOneCodeEditor('data_importer_style_code', cssSettings, function(cm) {
			styleCodeEditor = cm;
		});
		initOneCodeEditor('data_importer_script_code', jsSettings, function(cm) {
			scriptCodeEditor = cm;
		});

		// Layout refresh after two-column layout paints (all instances).
		setTimeout(function() {
			[templateEditor, wrapperBeforeEditor, wrapperAfterEditor, styleCodeEditor, scriptCodeEditor].forEach(function(cm) {
				if (cm && cm.refresh) {
					cm.refresh();
				}
			});
		}, 150);
	}

	// ------------------------------------------------------------------
	// Tag insertion
	// ------------------------------------------------------------------

	function getEditorForTarget(target) {
		if (target === 'before') {
			return wrapperBeforeEditor;
		}
		if (target === 'after') {
			return wrapperAfterEditor;
		}
		return templateEditor;
	}

	function getTextareaForTarget(target) {
		if (target === 'before') {
			return document.getElementById('data_importer_wrapper_before');
		}
		if (target === 'after') {
			return document.getElementById('data_importer_wrapper_after');
		}
		return document.getElementById('data_importer_template_html');
	}

	function insertAtCursor(tag, target) {
		var area = target || 'template';
		var editor = getEditorForTarget(area);
		if (editor) {
			editor.replaceSelection(tag);
			editor.focus();
			return;
		}

		var textarea = getTextareaForTarget(area);
		if (!textarea) {
			return;
		}

		var start = textarea.selectionStart;
		var end   = textarea.selectionEnd;
		var val   = textarea.value;

		textarea.value = val.substring(0, start) + tag + val.substring(end);
		textarea.selectionStart = textarea.selectionEnd = start + tag.length;
		textarea.focus();
	}

	var extractVars = !!(cfg.extractVars);

	function fieldToLabel(field) {
		return field;
	}

	function fieldToPhpSnippet(field) {
		var parts = field.split('.');
		var varExpr;

		if (extractVars) {
			// Legacy extract() mode: $city ?? '' or $address['city'] ?? ''
			varExpr = '$' + parts[0];
			for (var i = 1; i < parts.length; i++) {
				varExpr += "['" + parts[i] + "']";
			}
		} else {
			// Default: $vars['city'] ?? '' or $vars['address']['city'] ?? ''
			varExpr = '$vars';
			for (var i = 0; i < parts.length; i++) {
				varExpr += "['" + parts[i] + "']";
			}
		}

		return "<?php echo esc_html( " + varExpr + " ?? '' ); ?>";
	}

	function initTagButtonLabels() {
		$('.data-importer-tag-button').each(function() {
			var field = $(this).data('field');
			if (field) {
				$(this).text(fieldToLabel(String(field)));
			}
		});
	}

	function bindTagButtons() {
		$(document).on('click', '.data-importer-tag-button', function() {
			var field = $(this).data('field');
			var target = $(this).data('target') || 'template';
			if (field) {
				insertAtCursor(fieldToPhpSnippet(String(field)), String(target));
			}
		});
	}

	// ------------------------------------------------------------------
	// Clear data (AJAX)
	// ------------------------------------------------------------------

	function bindClearData() {
		$('#data-importer-clear-button').on('click', function() {
			var msg = $(this).data('confirm') || i18n.clearConfirm || 'Clear all imported data?';
			if (!window.confirm(msg)) {
				return;
			}

			$.ajax({
				url:      cfg.ajaxUrl,
				type:     'POST',
				dataType: 'json',
				data: {
					action:    'data_importer_clear_data',
					nonce:     cfg.nonce,
					source_id: cfg.sourceId || 0
				}
			})
			.done(function(resp) {
				if (resp.success) {
					var url = new URL(window.location.href);
					url.searchParams.set('data_cleared', '1');
					window.location.assign(url.toString());
				}
			});
		});
	}

	// ------------------------------------------------------------------
	// Delete source confirmation (list view row action)
	// ------------------------------------------------------------------

	function bindDeleteForms() {
		$(document).on('submit', '.data-importer-delete-form', function(e) {
			var msg = $(this).data('confirm') || i18n.deleteConfirm || 'Delete the data source? This action cannot be undone.';
			if (!window.confirm(msg)) {
				e.preventDefault();
			}
		});
	}

	function bindConfirmButtons() {
		$(document).on('click', '.data-importer-confirm-action', function(e) {
			var msg = $(this).data('confirm') || i18n.regenConfirm || '';
			if (msg && !window.confirm(msg)) {
				e.preventDefault();
			}
		});
	}

	// ------------------------------------------------------------------
	// Clipboard copy
	// ------------------------------------------------------------------

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(function() {
				speak(i18n.copied || 'Copied.');
			}).catch(function() {
				legacyCopy(text);
			});
		} else {
			legacyCopy(text);
		}
	}

	function legacyCopy(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity  = '0';
		document.body.appendChild(ta);
		ta.focus();
		ta.select();
		try {
			document.execCommand('copy');
			speak(i18n.copied || 'Copied.');
		} catch (e) {
			speak(i18n.copyFailed || 'Could not copy.');
		}
		document.body.removeChild(ta);
	}

	function speak(msg) {
		if (window.wp && wp.a11y && wp.a11y.speak) {
			wp.a11y.speak(msg);
		}
	}

	function showCopyFeedback($btn) {
		if ($btn.hasClass('is-copied')) {
			return;
		}
		var $icon = $btn.find('.dashicons');
		$btn.addClass('is-copied');
		$icon.removeClass('dashicons-clipboard').addClass('dashicons-yes-alt');
		setTimeout(function() {
			$btn.removeClass('is-copied');
			$icon.removeClass('dashicons-yes-alt').addClass('dashicons-clipboard');
		}, 2000);
	}

	function bindCopyButtons() {
		// data-clipboard-target: copies value from a form element by id
		// data-clipboard-text:   copies the attribute value directly
		$(document).on('click', '.data-importer-copy', function() {
			var $btn = $(this);
			var target = $btn.data('clipboard-target');
			var direct = $btn.data('clipboard-text');
			var text = '';

			if (direct) {
				text = String(direct);
			} else if (target) {
				var el = document.getElementById(target);
				if (el) {
					text = el.value || el.textContent || '';
				}
			}

			if (!text) {
				return;
			}

			copyText(text);
			showCopyFeedback($btn);
		});
	}

	// ------------------------------------------------------------------
	// Records tab: record preview (pretty-printed JSON)
	// ------------------------------------------------------------------

	function b64ToUtf8(b64) {
		var binary = atob(b64);
		var bytes = new Uint8Array(binary.length);
		for (var i = 0; i < binary.length; i++) {
			bytes[i] = binary.charCodeAt(i);
		}
		return new TextDecoder('utf-8').decode(bytes);
	}

	function formatRecordForPreview(raw) {
		if (!raw) {
			return '';
		}
		try {
			return JSON.stringify(JSON.parse(raw), null, 2);
		} catch (e) {
			return raw;
		}
	}

	function ensureRecordModal() {
		var existing = document.getElementById('data-importer-record-modal');
		if (existing) {
			return existing;
		}

		var wrap = document.createElement('div');
		wrap.id = 'data-importer-record-modal';
		wrap.className = 'data-importer-modal';
		wrap.setAttribute('hidden', 'hidden');
		wrap.innerHTML =
			'<div class="data-importer-modal__backdrop" tabindex="-1"></div>' +
			'<div class="data-importer-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="data-importer-modal-title">' +
			'<div class="data-importer-modal__header">' +
			'<h2 id="data-importer-modal-title" class="data-importer-modal__title"></h2>' +
			'<button type="button" class="button-link data-importer-modal__close">' +
			'<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>' +
			'</button>' +
			'</div>' +
			'<div class="data-importer-modal__body"><pre class="data-importer-modal__pre"></pre></div>' +
			'</div>';

		document.body.appendChild(wrap);

		var closeLabel = i18n.closeModal || 'Close';

		wrap.querySelector('.data-importer-modal__close').setAttribute('aria-label', closeLabel);

		return wrap;
	}

	function bindRecordPreviewModal() {
		var modalEl = null;
		var lastFocus = null;
		var onKeydown = null;

		function closeModal() {
			if (!modalEl || modalEl.hasAttribute('hidden')) {
				return;
			}
			modalEl.setAttribute('hidden', 'hidden');
			modalEl.classList.remove('is-open');
			document.body.classList.remove('data-importer-modal-open');
			if (onKeydown) {
				document.removeEventListener('keydown', onKeydown);
				onKeydown = null;
			}
			if (lastFocus && typeof lastFocus.focus === 'function') {
				lastFocus.focus();
			}
		}

		function openModal(b64, triggerBtn) {
			var raw = '';
			try {
				raw = b64ToUtf8(b64);
			} catch (e) {
				raw = '';
			}

			modalEl = ensureRecordModal();
			lastFocus = triggerBtn || document.activeElement;

			var title = modalEl.querySelector('.data-importer-modal__title');
			var pre = modalEl.querySelector('.data-importer-modal__pre');
			title.textContent = i18n.recordModalTitle || 'Record Content';
			pre.textContent = formatRecordForPreview(raw);

			modalEl.removeAttribute('hidden');
			modalEl.classList.add('is-open');
			document.body.classList.add('data-importer-modal-open');

			var closeBtn = modalEl.querySelector('.data-importer-modal__close');
			if (closeBtn) {
				closeBtn.focus();
			}

			onKeydown = function(ev) {
				if (ev.key === 'Escape') {
					ev.preventDefault();
					closeModal();
				}
			};
			document.addEventListener('keydown', onKeydown);

			if (window.wp && wp.a11y && wp.a11y.speak) {
				wp.a11y.speak(i18n.recordModalTitle || '');
			}
		}

		$(document).on('click', '.data-importer-record-preview', function(e) {
			e.preventDefault();
			var b64 = $(this).attr('data-record-b64');
			if (!b64) {
				return;
			}
			openModal(b64, this);
		});

		$(document).on('click', '#data-importer-record-modal .data-importer-modal__backdrop, #data-importer-record-modal .data-importer-modal__close', function(e) {
			e.preventDefault();
			closeModal();
		});
	}

	// ------------------------------------------------------------------
	// Slug auto-generation (new-source form)
	// ------------------------------------------------------------------

	function bindSlugGenerator() {
		var nameField = document.getElementById('data_importer_name');
		var slugField = document.getElementById('data_importer_slug');

		if (!nameField || !slugField) {
			return;
		}

		var slugEdited = false;

		$(slugField).on('input', function() {
			slugEdited = !!$(this).val();
		});

		$(nameField).on('input', function() {
			if (slugEdited) {
				return;
			}
			var slug = $(this).val()
				.toLowerCase()
				.replace(/[åä]/g, 'a')
				.replace(/ö/g, 'o')
				.replace(/[^a-z0-9]+/g, '-')
				.replace(/^-+|-+$/g, '');

			$(slugField).val(slug);
		});
	}

	// ------------------------------------------------------------------
	// Template asset repeaters
	// ------------------------------------------------------------------

	function buildAssetHandleFromSource(source) {
		var clean = String(source || '').trim();
		if (!clean) {
			return '';
		}

		try {
			clean = new URL(clean, window.location.origin).pathname || clean;
		} catch (e) {
			clean = clean.split('?')[0].split('#')[0];
		}

		var parts = clean.split('/');
		var basename = parts.pop() || '';
		basename = basename.replace(/\.[^.]+$/, '');

		return basename
			.toLowerCase()
			.replace(/[^a-z0-9_-]+/g, '-')
			.replace(/^-+|-+$/g, '');
	}

	function refreshAssetRow(row) {
		var sourceField = row.querySelector('.data-importer-asset-source');
		var handleField = row.querySelector('.data-importer-asset-handle');

		if (!sourceField || !handleField) {
			return;
		}

		var defaultPlaceholder = handleField.getAttribute('data-default-placeholder') || '';
		var generatedHandle = buildAssetHandleFromSource(sourceField.value);
		handleField.setAttribute('placeholder', generatedHandle || defaultPlaceholder);
	}

	function wireAssetRow(row) {
		if (!row) {
			return;
		}

		var sourceField = row.querySelector('.data-importer-asset-source');
		var handleField = row.querySelector('.data-importer-asset-handle');

		if (!sourceField || !handleField) {
			return;
		}

		refreshAssetRow(row);

		$(sourceField).on('input', function() {
			refreshAssetRow(row);
		});

		$(handleField).on('input', function() {
			row.setAttribute('data-auto-handle', handleField.value ? '0' : '1');
			refreshAssetRow(row);
		});
	}

	function reindexAssetRows(group) {
		if (!group) {
			return;
		}

		var type = group.getAttribute('data-asset-type') === 'script' ? 'scripts' : 'styles';
		var baseName = type === 'scripts' ? 'data_importer_template_scripts' : 'data_importer_template_styles';
		var rows = group.querySelectorAll('.data-importer-asset-row');

		rows.forEach(function(row, index) {
			var sourceField = row.querySelector('.data-importer-asset-source');
			var handleField = row.querySelector('.data-importer-asset-handle');

			if (sourceField) {
				sourceField.setAttribute('name', baseName + '[' + index + '][src]');
			}
			if (handleField) {
				handleField.setAttribute('name', baseName + '[' + index + '][handle]');
			}
		});
	}

	function reindexAllAssetRows() {
		document.querySelectorAll('.data-importer-asset-group').forEach(function(group) {
			reindexAssetRows(group);
		});
	}

	function createAssetRow(type) {
		var templateId = type === 'script' ? 'data-importer-script-row-template' : 'data-importer-style-row-template';
		var template = document.getElementById(templateId);

		if (!template || !('content' in template)) {
			return null;
		}

		var fragment = template.content.cloneNode(true);
		var row = fragment.querySelector('.data-importer-asset-row');

		if (!row) {
			return null;
		}

		if (row.querySelector('.data-importer-asset-handle')) {
			row.setAttribute('data-auto-handle', '1');
			wireAssetRow(row);
		}

		return fragment;
	}

	function bindTemplateAssetRepeaters() {
		$('.data-importer-asset-row').each(function() {
			wireAssetRow(this);
		});
		reindexAllAssetRows();

		$(document).on('submit', '#data-importer-save-template-form', function() {
			reindexAllAssetRows();
		});

		$(document).on('click', '.data-importer-add-asset', function() {
			var type = $(this).data('assetType') || $(this).attr('data-asset-type');
			var group = this.closest('.data-importer-asset-group');
			var rows = group ? group.querySelector('.data-importer-asset-rows') : null;
			var fragment = rows ? createAssetRow(String(type)) : null;

			if (!rows || !fragment) {
				return;
			}

			rows.appendChild(fragment);
			reindexAssetRows(group);
		});

		$(document).on('click', '.data-importer-remove-asset', function() {
			var row = this.closest('.data-importer-asset-row');
			var rows = row ? row.parentNode : null;
			var type = row ? row.getAttribute('data-asset-type') : 'style';

			if (!row || !rows) {
				return;
			}

			row.remove();

			if (!rows.querySelector('.data-importer-asset-row')) {
				var fragment = createAssetRow(String(type));
				if (fragment) {
					rows.appendChild(fragment);
				}
			}

			reindexAssetRows(rows.closest('.data-importer-asset-group'));
		});
	}

	// ------------------------------------------------------------------
	// Create API Key (AJAX – inline reveal)
	// ------------------------------------------------------------------

	function bindCreateApiKey() {
		$(document).on('submit', '.di-create-api-key-form', function(e) {
			e.preventDefault();

			var form     = this;
			var $form    = $(form);
			var $btn     = $form.find('[type="submit"]');
			var sourceId = $form.find('[name="data_importer_source_id"]').val();
			var listEl   = document.getElementById('di-api-keys-list-' + sourceId);

			$btn.prop('disabled', true);

			$.ajax({
				url:      cfg.ajaxUrl,
				type:     'POST',
				dataType: 'json',
				data: (function() {
					var d = {};
					$.each($form.serializeArray(), function(_, item) {
						d[item.name] = item.value;
					});
					d.action = 'data_importer_create_api_key_ajax';
					return d;
				})()
			})
			.done(function(resp) {
				if (!resp || !resp.success) {
					var msg = (resp && resp.data && resp.data.message)
						? resp.data.message
						: (i18n.createKeyError || 'Could not create API key.');
					alert(msg);
					return;
				}

				// Inject new key card at top of list
				if (resp.data.card_html && listEl) {
					$(listEl).find('.di-no-keys-message').remove();
					$(listEl).prepend(resp.data.card_html);

					// Show the reveal inside the newly added card
					var $newCard = $(listEl).children(':first');
					var $reveal  = $newCard.find('.di-key-reveal');
					if ($reveal.length && resp.data.secret) {
						$reveal.find('input[type="text"]').val(resp.data.secret);
						$reveal[0].removeAttribute('hidden');
						$reveal.find('input[type="text"]')[0].select();
					}
				}

				// Reset the form
				form.reset();

				if (window.wp && wp.a11y && wp.a11y.speak) {
					wp.a11y.speak(i18n.keyCreated || 'API key created.');
				}
			})
			.fail(function() {
				alert(i18n.createKeyError || 'Could not create API key.');
			})
			.always(function() {
				$btn.prop('disabled', false);
			});
		});
	}

	// ------------------------------------------------------------------
	// Init
	// ------------------------------------------------------------------

	$(document).ready(function() {
		initCodeEditor();
		initTagButtonLabels();
		bindTagButtons();
		bindClearData();
		bindDeleteForms();
		bindConfirmButtons();
		bindCopyButtons();
		bindRecordPreviewModal();
		bindSlugGenerator();
		bindTemplateAssetRepeaters();
		bindCreateApiKey();
	});

})(window.jQuery);
