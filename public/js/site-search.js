/*!
 * pdir site search – suggestions for the search form
 *
 * Progressive enhancement: the form works without this script (GET to the
 * results page). The script adds a WAI-ARIA combobox (APG pattern "list
 * autocomplete") with debounced, abortable requests to the search endpoint.
 *
 * Besides hits, the list can contain search terms: popular searches for an
 * empty field and "Did you mean …?" if there are no hits. Selecting a term
 * submits the form with it.
 *
 * Events (dispatched on the form, bubbling):
 *  - site-search:results  detail: { query, totalHits, suggestion }
 *  - site-search:select   detail: { query, url, title, position, hit }; cancelable:
 *                         preventDefault() stays on the page instead of opening the hit
 */
(function () {
  'use strict';

  var DEBOUNCE_MS = 200;
  var instances = 0;

  function init(form) {
    if (form.siteSearch) {
      return;
    }

    var config;

    try {
      config = JSON.parse(form.getAttribute('data-site-search') || '{}');
    } catch (e) {
      return;
    }

    var input = form.querySelector('.site-search__input');
    var panel = form.querySelector('.site-search__panel');
    var listbox = form.querySelector('.site-search__listbox');
    var showAll = form.querySelector('.site-search__all');
    var status = form.querySelector('.site-search__status');

    if (!input || !panel || !listbox || !config.endpoint) {
      return;
    }

    var messages = config.messages || {};
    var minLength = config.minLength || 2;
    var idPrefix = listbox.id || 'site-search-' + ++instances;
    // { type: 'hit', hit } or { type: 'term', term }
    var items = [];
    var active = -1;
    var timer = null;
    var controller = null;
    var currentQuery = '';
    var popular = null;

    form.siteSearch = true;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', listbox.id);

    function format(message, value) {
      return String(message || '').replace('%s', value);
    }

    function setStatus(text) {
      if (status) {
        status.textContent = text;
      }
    }

    function open() {
      panel.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function close() {
      panel.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      setActive(-1);
    }

    function setActive(index) {
      var options = listbox.querySelectorAll('[role="option"]');

      active = index;

      for (var i = 0; i < options.length; i++) {
        options[i].setAttribute('aria-selected', i === index ? 'true' : 'false');
      }

      if (index >= 0 && options[index]) {
        input.setAttribute('aria-activedescendant', options[index].id);
        options[index].scrollIntoView({ block: 'nearest' });
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    /**
     * Appends text with highlights. Only <em> from the endpoint is turned into
     * <mark>; everything else ends up as plain text (no innerHTML).
     */
    function appendHighlighted(target, html, plain) {
      if (!config.highlight || typeof html !== 'string') {
        target.appendChild(document.createTextNode(plain || ''));
        return;
      }

      var doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');

      doc.body.childNodes.forEach(function (node) {
        if (node.nodeType === Node.ELEMENT_NODE && node.nodeName === 'EM') {
          var mark = document.createElement('mark');
          mark.textContent = node.textContent;
          target.appendChild(mark);
        } else {
          target.appendChild(document.createTextNode(node.textContent));
        }
      });
    }

    function createOption(index, className) {
      var option = document.createElement('li');

      option.id = idPrefix + '-option-' + index;
      option.className = 'site-search__option' + (className ? ' ' + className : '');
      option.setAttribute('role', 'option');
      option.setAttribute('aria-selected', 'false');

      return option;
    }

    function renderHit(hit, index) {
      var formatted = hit._formatted || {};
      var option = createOption(index);
      var title = document.createElement('span');

      if (hit.image) {
        var image = document.createElement('img');
        image.className = 'site-search__image';
        image.src = hit.image;
        image.alt = '';
        image.width = 40;
        image.height = 40;
        image.loading = 'lazy';
        option.appendChild(image);
      }

      var body = document.createElement('span');
      body.className = 'site-search__option-body';

      title.className = 'site-search__title';
      appendHighlighted(title, formatted.title, hit.title);
      body.appendChild(title);

      if (hit.description) {
        var snippet = document.createElement('span');
        snippet.className = 'site-search__snippet';
        appendHighlighted(snippet, formatted.description, hit.description);
        body.appendChild(snippet);
      }

      option.appendChild(body);

      return option;
    }

    function renderTerm(term, index) {
      var option = createOption(index, 'site-search__option--term');
      var title = document.createElement('span');

      title.className = 'site-search__title';
      title.textContent = term;
      option.appendChild(title);

      return option;
    }

    /**
     * Renders the items; label is an optional group heading ("Popular searches").
     */
    function renderItems(newItems, label) {
      items = newItems;
      listbox.textContent = '';

      if (label && items.length) {
        // Presentational heading: the listbox itself only contains options
        var group = document.createElement('li');
        group.className = 'site-search__group';
        group.setAttribute('role', 'presentation');
        group.setAttribute('aria-hidden', 'true');
        group.textContent = label;
        listbox.appendChild(group);
      }

      items.forEach(function (item, index) {
        listbox.appendChild('hit' === item.type ? renderHit(item.hit, index) : renderTerm(item.term, index));
      });

      setActive(-1);
    }

    function render(result) {
      var hits = (result && result.hits) || [];
      var total = (result && result.totalHits) || 0;
      var suggestion = (result && result.suggestion) || null;

      if (!hits.length && suggestion) {
        renderItems([{ type: 'term', term: suggestion }], messages.didYouMean ? format(messages.didYouMean, suggestion) : '');
      } else {
        renderItems(hits.map(function (hit) { return { type: 'hit', hit: hit }; }));
      }

      if (showAll) {
        var showLink = config.resultsUrl && total > hits.length;

        showAll.hidden = !showLink;

        if (showLink) {
          var url = new URL(config.resultsUrl, window.location.href);
          url.searchParams.set('keywords', currentQuery);
          showAll.href = url.toString();
          showAll.textContent = format(messages.showAll, total);
        }
      }

      if (hits.length) {
        open();
        setStatus(format(messages.results, total));
      } else if (suggestion) {
        open();
        setStatus(format(messages.didYouMean, suggestion));
      } else {
        // Keep the panel closed, but announce that there are no results
        close();
        setStatus(messages.noResults || '');
      }

      form.dispatchEvent(new CustomEvent('site-search:results', { bubbles: true, detail: { query: currentQuery, totalHits: total, suggestion: suggestion } }));
    }

    function showPopular() {
      if (!config.popularEndpoint || input.value.trim() !== '') {
        return;
      }

      if (popular) {
        if (popular.length) {
          renderItems(popular.map(function (term) { return { type: 'term', term: term }; }), messages.popular);
          if (showAll) {
            showAll.hidden = true;
          }
          open();
        }

        return;
      }

      var url = new URL(config.popularEndpoint, window.location.href);

      if (config.lang) {
        url.searchParams.set('lang', config.lang);
      }

      fetch(url.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (response) {
          return response.ok ? response.json() : { terms: [] };
        })
        .then(function (result) {
          popular = Array.isArray(result.terms) ? result.terms : [];

          if (document.activeElement === input) {
            showPopular();
          }
        })
        .catch(function () {
          popular = [];
        });
    }

    function search(query) {
      if (controller) {
        controller.abort();
      }

      currentQuery = query;

      if (query.length < minLength) {
        items = [];
        listbox.textContent = '';
        close();
        setStatus('');

        if ('' === query) {
          showPopular();
        }

        return;
      }

      var url = new URL(config.endpoint, window.location.href);
      url.searchParams.set('keywords', query);
      url.searchParams.set('limit', String(config.limit || 8));

      if (config.lang) {
        url.searchParams.set('lang', config.lang);
      }

      controller = typeof AbortController === 'function' ? new AbortController() : null;

      fetch(url.toString(), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal: controller ? controller.signal : undefined,
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('HTTP ' + response.status);
          }

          return response.json();
        })
        .then(function (result) {
          // Ignore late answers for an older query
          if (query === currentQuery) {
            render(result);
          }
        })
        .catch(function (error) {
          if (error && error.name === 'AbortError') {
            return;
          }

          close();
          setStatus(messages.error || '');
        });
    }

    function submitTerm(term) {
      input.value = term;
      close();

      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    }

    function select(index) {
      var item = items[index];

      if (!item) {
        return;
      }

      if ('term' === item.type) {
        submitTerm(item.term);
        return;
      }

      if (!item.hit.url) {
        return;
      }

      // A listener can cancel the navigation, e.g. to fill a form field with the hit
      if (!track(item.hit, index)) {
        close();
        return;
      }

      window.location.href = item.hit.url;
    }

    /**
     * Counts the search and the click in the anonymous search log (no personal
     * data). sendBeacon survives the navigation to the hit.
     */
    function logClick() {
      if (!config.logEndpoint || !navigator.sendBeacon) {
        return;
      }

      var data = new URLSearchParams();
      data.set('keywords', currentQuery);
      data.set('click', '1');

      if (config.lang) {
        data.set('lang', config.lang);
      }

      try {
        navigator.sendBeacon(new URL(config.logEndpoint, window.location.href).toString(), data);
      } catch (e) {
        // Logging must never break the navigation
      }
    }

    function track(hit, index) {
      var detail = { query: currentQuery, url: hit.url, title: hit.title, position: index + 1, hit: hit };

      logClick();

      var proceed = form.dispatchEvent(new CustomEvent('site-search:select', { bubbles: true, cancelable: true, detail: detail }));

      if (config.tracking) {
        if (window._paq && typeof window._paq.push === 'function') {
          window._paq.push(['trackEvent', 'SiteSearch', 'Click', detail.query + ' → ' + detail.url, detail.position]);
        }

        if (window.dataLayer && typeof window.dataLayer.push === 'function') {
          window.dataLayer.push({ event: 'SiteSearchClick', siteSearch: detail });
        }
      }

      return proceed;
    }

    input.addEventListener('input', function () {
      var query = input.value.trim();

      clearTimeout(timer);
      timer = setTimeout(function () {
        search(query);
      }, DEBOUNCE_MS);
    });

    input.addEventListener('focus', function () {
      if (input.value.trim() === '') {
        showPopular();
      } else if (items.length && input.value.trim() === currentQuery) {
        open();
      }
    });

    input.addEventListener('keydown', function (event) {
      var expanded = !panel.hidden && items.length > 0;

      switch (event.key) {
        case 'ArrowDown':
          event.preventDefault();

          if (!expanded) {
            if (items.length) {
              open();
            } else {
              showPopular();
            }

            return;
          }

          setActive(active + 1 >= items.length ? 0 : active + 1);
          break;

        case 'ArrowUp':
          if (!expanded) {
            return;
          }

          event.preventDefault();
          setActive(active - 1 < 0 ? items.length - 1 : active - 1);
          break;

        case 'Enter':
          // Without an active option the form is submitted to the results page
          if (expanded && active >= 0) {
            event.preventDefault();
            select(active);
          }
          break;

        case 'Escape':
          if (expanded) {
            event.preventDefault();
            close();
          } else if (input.value !== '') {
            event.preventDefault();
            input.value = '';
            search('');
          }
          break;

        case 'Tab':
          close();
          break;
      }
    });

    function optionIndex(target) {
      var option = target.closest('[role="option"]');

      return option ? Array.prototype.indexOf.call(listbox.querySelectorAll('[role="option"]'), option) : -1;
    }

    // mousedown instead of click: keeps the focus in the input
    listbox.addEventListener('mousedown', function (event) {
      var index = optionIndex(event.target);

      if (index < 0) {
        return;
      }

      event.preventDefault();
      select(index);
    });

    listbox.addEventListener('mousemove', function (event) {
      var index = optionIndex(event.target);

      if (index !== active) {
        setActive(index);
      }
    });

    form.addEventListener('focusout', function (event) {
      if (!event.relatedTarget || !form.contains(event.relatedTarget)) {
        close();
      }
    });

    // Show suggestions for a prefilled query (e.g. on the results page) only after typing
    currentQuery = input.value.trim();
  }

  function initAll() {
    document.querySelectorAll('form[data-site-search]').forEach(init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
