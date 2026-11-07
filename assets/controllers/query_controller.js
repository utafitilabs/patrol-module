/*
 * This file is part of the UhifadhiLabs Patrol Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

import { Controller } from '@hotwired/stimulus';

/*
 * A FILTER ROW THAT RE-ASKS THE SERVER.
 *
 * The row is a GET form and its dropdowns are the shell's, which write no
 * input of their own; a choice is carried into the hidden field of the same
 * name and the form is sent. So a narrowed list stays an address somebody can
 * send, and every count beside an option stays the server's.
 *
 * The event and its detail are the shell's dropdown contract (ShellBundle
 * docs/dropdown.md): `uhifadhi--shell-bundle--dropdown:choose` on the
 * wrapper, bubbling, `detail: {value}`. An empty value clears the field, so
 * the address carries only what is chosen.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/HTMLFormElement/requestSubmit
 */
export default class extends Controller {
    chosen(event) {
        const wrapper = event.target.closest('[data-dd]');
        if (!wrapper || !(this.element instanceof HTMLFormElement)) {
            return;
        }

        const name = wrapper.dataset.dd;
        let field = this.element.querySelector(`input[type="hidden"][name="${name}"]`);
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = name;
            this.element.appendChild(field);
        }

        const value = event.detail?.value == null ? '' : String(event.detail.value);
        field.value = value;
        field.disabled = '' === value;
        this.element.requestSubmit();
    }
}
