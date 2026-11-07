/**
 * Brand Logo plugin: sender avatars in the message list
 *
 * @license GNU GPLv3+
 */
(function () {
    var palette = ['#d32f2f', '#c2185b', '#7b1fa2', '#512da8', '#303f9f', '#1976d2', '#0288d1',
        '#00838f', '#00796b', '#388e3c', '#689f38', '#e64a19', '#5d4037', '#546e7a'];

    function hash(str) {
        var h = 0;
        for (var i = 0; i < str.length; i++) {
            h = (h * 31 + str.charCodeAt(i)) | 0;
        }
        return Math.abs(h);
    }

    function sender(tr) {
        var el = tr.querySelector('.fromto .rcmContactAddress, .from .rcmContactAddress, .to .rcmContactAddress');
        if (!el) {
            return null;
        }

        var text = (el.textContent || '').trim(),
            match = (el.getAttribute('title') || text).match(/[^\s<>"]+@[^\s<>"]+/);

        return match ? { email: match[0].toLowerCase(), name: text || match[0] } : null;
    }

    function decorate(tr) {
        var td = tr.querySelector('td.subject'), s;

        if (!td || td.querySelector('.brand-logo-avatar') || !(s = sender(tr))) {
            return;
        }

        var avatar = document.createElement('span'),
            initial = document.createElement('span'),
            img = document.createElement('img'),
            letter = s.name.replace(/^[^\p{L}\p{N}]+/u, '').charAt(0) || '?';

        avatar.className = 'brand-logo-avatar';
        avatar.setAttribute('aria-hidden', 'true');
        avatar.style.backgroundColor = palette[hash(s.email) % palette.length];

        initial.className = 'brand-logo-initial';
        initial.textContent = letter.toUpperCase();

        img.alt = '';
        img.loading = 'lazy';
        img.decoding = 'async';
        img.onload = function () {
            // a 1x1 image means "no picture": keep the initial
            if (img.naturalWidth > 1) {
                avatar.classList.add('has-logo');
            } else {
                img.remove();
            }
        };
        img.onerror = function () {
            img.remove();
        };
        img.src = rcmail.url('addressbook/photo', { _email: s.email, _bl: 1 });

        avatar.appendChild(initial);
        avatar.appendChild(img);
        td.insertBefore(avatar, td.firstChild);
    }

    if (window.rcmail) {
        rcmail.addEventListener('init', function () {
            var list = rcmail.gui_objects.messagelist;

            if (!list || !rcmail.message_list || (rcmail.env.layout && rcmail.env.layout != 'widescreen')) {
                return;
            }

            $(list).addClass('brand-logos');

            rcmail.addEventListener('insertrow', function (e) {
                if (e.uid && e.row && e.row.obj) {
                    decorate(e.row.obj);
                }
            });

            $('tbody > tr', list).each(function () {
                decorate(this);
            });
        });
    }
})();
