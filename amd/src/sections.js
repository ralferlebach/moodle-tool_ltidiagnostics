// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Asynchronous filters, paging and page sizes of the connection report sections (issues #21, #23, #25).
 *
 * Progressive enhancement: every form and link of a section also works as a normal GET request. With JavaScript the
 * module asks tool_ltidiagnostics_render_section for the same state, replaces only the section, keeps the browser history
 * (back and forward restore the state) and announces the result in a live region. On any error it falls back to the
 * normal page load.
 *
 * @module     tool_ltidiagnostics/sections
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {get_string as getString} from 'core/str';

const SELECTOR = '[data-ltidiag-section]';

/**
 * Query string of a form (GET forms only).
 *
 * @param {HTMLFormElement} form The form.
 * @returns {string}
 */
const formQuery = (form) => new URLSearchParams(new FormData(form)).toString();

/**
 * Load a section and replace it.
 *
 * @param {HTMLElement} section The section container.
 * @param {string} query Query string.
 * @param {string} fallback URL to load when anything fails.
 * @param {boolean} push Add a history entry.
 * @returns {Promise}
 */
const load = (section, query, fallback, push) => {
    section.setAttribute('aria-busy', 'true');
    return Ajax.call([{
        methodname: 'tool_ltidiagnostics_render_section',
        args: {key: section.dataset.key, section: section.dataset.ltidiagSection, query: query},
    }])[0].then((result) => {
        section.innerHTML = result.html;
        section.removeAttribute('aria-busy');
        if (push) {
            // Keep the parameters of the other sections of the page; replace only those of this section.
            const current = new URL(window.location.href);
            const next = new URL(result.url, window.location.href);
            (section.dataset.params || '').split(',').filter(Boolean).forEach((name) => current.searchParams.delete(name));
            next.searchParams.forEach((value, name) => current.searchParams.set(name, value));
            window.history.pushState({ltidiagSection: section.dataset.ltidiagSection, query: query}, '', current.toString());
        }
        return getString('ajax_updated', 'tool_ltidiagnostics', result.total);
    }).then((text) => {
        const live = document.getElementById('tool_ltidiagnostics_live');
        if (live) {
            live.textContent = text;
        }
        if (push) {
            // Move the focus to the updated section so that keyboard and screen reader users continue there.
            section.setAttribute('tabindex', '-1');
            section.focus({preventScroll: false});
        }
        return true;
    }).catch(() => {
        window.location.href = fallback;
    });
};

/**
 * Section a link or form belongs to (or the section it names).
 *
 * @param {HTMLElement} element Link or form.
 * @returns {HTMLElement|null}
 */
const targetSection = (element) => {
    const name = element.dataset.ltidiagTarget;
    if (name) {
        return document.querySelector('[data-ltidiag-section="' + name + '"]');
    }
    return element.closest(SELECTOR);
};

/**
 * Initialise the module.
 */
export const init = () => {
    if (!document.querySelector(SELECTOR)) {
        return;
    }
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.classList.contains('ltidiag-filter')) {
            return;
        }
        const section = targetSection(form);
        if (!section || form.method.toLowerCase() !== 'get') {
            return;
        }
        e.preventDefault();
        const query = formQuery(form);
        load(section, query, form.action + '?' + query, true);
    });
    document.addEventListener('click', (e) => {
        const link = e.target.closest('a.ltidiag-nav, a[data-ltidiag-target]');
        if (!link || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) {
            return;
        }
        const section = targetSection(link);
        if (!section) {
            return;
        }
        e.preventDefault();
        const url = new URL(link.href, window.location.href);
        load(section, url.searchParams.toString(), link.href, true);
    });
    document.addEventListener('change', (e) => {
        const box = e.target;
        if (box.classList && box.classList.contains('ltidiag-pair-all')) {
            // The row boxes belong to the selection form through their form attribute; they live in the same section.
            const scope = box.closest(SELECTOR) || document;
            scope.querySelectorAll('input.ltidiag-pair').forEach((other) => {
                other.checked = box.checked;
            });
        }
    });
    window.addEventListener('popstate', (e) => {
        if (e.state && e.state.ltidiagSection) {
            const section = document.querySelector('[data-ltidiag-section="' + e.state.ltidiagSection + '"]');
            if (section) {
                load(section, e.state.query, window.location.href, false);
                return;
            }
        }
        window.location.reload();
    });
};
