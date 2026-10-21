/* EIPSI administrative presentation only. Never apply to authored values or DOM trees. */
(function (root) {
    'use strict';
    const settings = root.eipsiAdminLanguage || {};
    const language = settings.language === 'es' ? 'es' : 'en';
    const messages = settings.messages || {};
    const own = key => Object.prototype.hasOwnProperty.call(messages, key) ? messages[key] : undefined;
    const t = english => {
        const entry = own(english);
        return language === 'es' && entry !== undefined ? (typeof entry === 'string' ? entry : entry.one) : english;
    };
    const format = (message, args) => {
        let cursor = 0;
        return message.replace(/%%|%(?:(\d+)\$)?([sd])/g, (token, position, type) => {
            if (token === '%%') return '%';
            const value = args[position ? Number(position) - 1 : cursor++];
            return type === 'd' ? String(Math.trunc(Number(value))) : String(value);
        });
    };
    root.EIPSIAdminI18n = Object.freeze({
        language, t,
        error: message => {
            const fields=settings.fields || {};
            if(fields['admin_error_'+message])return t(fields['admin_error_'+message]);
            for(const [key,english] of Object.entries(fields))if(key.startsWith('admin_error_prefix_')) {
                const prefix=key.slice('admin_error_prefix_'.length);
                if(message.startsWith(prefix))return format(t(english),[message.slice(prefix.length)]);
            }
            for(const [key,english] of Object.entries(fields))if(key.startsWith('admin_error_pattern_')) {
                const [start,end]=key.slice('admin_error_pattern_'.length).split('%s');
                if(message.startsWith(start)&&message.endsWith(end))return format(t(english),[message.slice(start.length,message.length-end.length)]);
            }
            return t(message);
        },
        h: english => t(english).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char])),
        field: key => t((settings.fields || {})[key] || key),
        f: (english, ...args) => format(t(english), args),
        n: (singular, count, ...args) => {
            const entry = own(singular);
            if (!entry || typeof entry === 'string') throw new Error('EIPSI_ADMIN_PLURAL_MISSING');
            const text = language === 'es' ? (Number(count) === 1 ? entry.one : entry.other) : (Number(count) === 1 ? singular : entry.plural);
            return format(text, [count, ...args]);
        }
    });
})(window);
