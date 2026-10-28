/**
 * Tourfecto - AI Chat Platform Frontend Helpers
 * أيقونات SVG مدمجة + أدوات مساعدة لوحدة الشات
 * @version 1.0.0
 */
(function () {
    'use strict';

    var ICONS = {
        'inbox': '<rect x="3" y="4" width="18" height="4" rx="1.5"/><rect x="3" y="10" width="18" height="4" rx="1.5"/><rect x="3" y="16" width="18" height="4" rx="1.5"/>',
        'search': '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'send': '<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>',
        'sparkles': '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3z"/><path d="M19 15l.9 2.4L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.6L19 15z"/>',
        'robot': '<rect x="4" y="8" width="16" height="12" rx="2.5"/><path d="M12 8V4"/><circle cx="12" cy="3" r="1"/><path d="M9 13h.01M15 13h.01M9 17c1.2.8 4.8.8 6 0"/><path d="M2 13v3M22 13v3"/>',
        'user': '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/>',
        'users': '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-3.5 3.1-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.2A4 4 0 0 1 16 11.8M22 21c0-3-2.2-4.8-5-5.3"/>',
        'clock': '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'calendar': '<rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M8 3v4M16 3v4M3 10h18"/>',
        'check': '<path d="M20 6L9 17l-5-5"/>',
        'check-circle': '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16.5 9"/>',
        'x': '<path d="M18 6L6 18M6 6l12 12"/>',
        'x-circle': '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'alert': '<path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5"/><path d="M12 18h.01"/>',
        'lock': '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'shield': '<path d="M12 3l8 3v6c0 4.5-3.2 7.7-8 9-4.8-1.3-8-4.5-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/>',
        'tag': '<path d="M3 3h8l10 10-8 8L3 11V3z"/><circle cx="8" cy="8" r="1.6"/>',
        'tag-plus': '<path d="M3 3h8l10 10-8 8L3 11V3z"/><circle cx="8" cy="8" r="1.6"/><path d="M12 14l5-5M14.5 11.5L12 14"/>',
        'plus': '<path d="M12 5v14M5 12h14"/>',
        'minus': '<path d="M5 12h14"/>',
        'refresh': '<path d="M21 12a9 9 0 1 1-2.6-6.4M21 3v6h-6"/>',
        'arrow-left': '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'arrow-right': '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'chevron-down': '<path d="M6 9l6 6 6-6"/>',
        'star': '<path d="M12 3l2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 18.3 5.9 21.6l1.4-6.8L2.2 10.1l6.9-.8L12 3z"/>',
        'flame': '<path d="M12 3s4 3.5 4 8a4 4 0 0 1-8 0c0-1.5.5-2.7 1-3.7C8.3 9 7 10.5 7 13a5 5 0 0 0 10 0c0-5-5-10-5-10z"/>',
        'phone': '<path d="M5 3h4l2 5-3 2c1.5 3 4 5.5 7 7l2-3 5 2v4c0 1.1-.9 2-2 2C10.6 20 4 13.4 4 5c0-1.1.9-2 2-2z"/>',
        'globe': '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'chat': '<path d="M4 5h16v11H8l-4 4V5z"/><path d="M8 9h8M8 12h5"/>',
        'mail': '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 7l9 6 9-6"/>',
        'instagram': '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17" cy="7" r="1.2"/>',
        'facebook': '<path d="M14 9h3l-.5 3H14v9h-3.5v-9H8V9h2.5V7.3c0-2.1 1.3-3.3 3.6-3.3H17V7h-2c-.9 0-1 .4-1 1v1z"/>',
        'whatsapp': '<path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3z"/><path d="M8.8 8.5c-.3 2.5 4 7.5 6.7 6.7l.9-1.7-2-1.1-1.1.9c-1-.5-2-1.5-2.5-2.5l.9-1.1-1.1-2-1.8.8z"/>',
        'settings': '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3h.1a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'database': '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        'bar-chart': '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'target': '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.4"/>',
        'trend-up': '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'trend-down': '<path d="M3 7l6 6 4-4 8 8"/><path d="M15 17h6v-6"/>',
        'percent': '<path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>',
        'briefcase': '<rect x="3" y="8" width="18" height="12" rx="2.5"/><path d="M9 8V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/>',
        'book': '<path d="M4 5a2 2 0 0 1 2-2h14v18H6a2 2 0 0 0-2 2V5z"/><path d="M4 19a2 2 0 0 1 2-2h14"/>',
        'mic': '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/>',
        'copy': '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'link': '<path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1.5 1.5"/><path d="M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1.5-1.5"/>',
        'edit': '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>',
        'eye': '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
        'pause': '<path d="M10 4v16M14 4v16"/>',
        'play': '<path d="M7 4v16l13-8-13-8z"/>',
        'download': '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
        'zap': '<path d="M13 2L4 14h6l-1 8 9-12h-6l1-8z"/>',
        'credit-card': '<rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20"/>',
        'refresh-cw': '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/>',
        'external': '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h5"/>',
        'folder': '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/>',
        'file-text': '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-5z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'info': '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>'
    };

    var CHANNEL_META = {
        whatsapp: { icon: 'whatsapp', label: 'واتساب', color: 'whatsapp', avatar: 'teal' },
        website_chat: { icon: 'globe', label: 'شات الموقع', color: 'website_chat', avatar: 'gold' },
        webchat: { icon: 'globe', label: 'شات الموقع', color: 'webchat', avatar: 'gold' },
        messenger: { icon: 'facebook', label: 'Messenger', color: 'messenger', avatar: 'purple' },
        instagram: { icon: 'instagram', label: 'Instagram', color: 'instagram', avatar: 'red' },
        email: { icon: 'mail', label: 'إيميل', color: 'email', avatar: 'purple' }
    };

    function icon(name, size) {
        var paths = ICONS[name] || ICONS.info;
        var s = size || 16;
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + paths + '</svg>';
    }

    function initials(name) {
        if (!name) return '؟';
        var parts = String(name).trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return '؟';
        if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
        return (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase();
    }

    function avatar(name, size, variant) {
        var cls = 'ch-avatar' + (size ? ' ' + size : '') + (variant ? ' ' + variant : '');
        return '<div class="' + cls + '">' + initials(name) + '</div>';
    }

    function channelBadge(channel, withLabel) {
        var m = CHANNEL_META[channel] || { icon: 'chat', label: channel || '-', color: '' };
        var label = withLabel ? '<span>' + m.label + '</span>' : '';
        return '<span class="ch-channel ' + m.color + '">' + icon(m.icon, 12) + label + '</span>';
    }

    function scoreBar(value, max) {
        var v = Math.max(0, Math.min(100, Math.round(Number(value || 0))));
        var m = max || 100;
        var pct = Math.round((v / m) * 100);
        var cls = v >= 70 ? 'red' : v >= 40 ? '' : '';
        return '<div class="ch-scorebar ' + cls + '"><i style="width:' + pct + '%;"></i></div>';
    }

    function rankBar(value, max) {
        var v = Number(value || 0);
        var m = max || v || 1;
        var pct = Math.round((v / m) * 100);
        return '<div class="ch-rank-bar"><i style="width:' + pct + '%;"></i></div>';
    }

    function pill(text, variant, withIcon) {
        return '<span class="ch-pill ' + (variant || '') + '">' + (withIcon ? icon(withIcon, 11) : '') + text + '</span>';
    }

    window.ChatUI = {
        icon: icon,
        avatar: avatar,
        initials: initials,
        channelBadge: channelBadge,
        channelMeta: CHANNEL_META,
        scoreBar: scoreBar,
        rankBar: rankBar,
        pill: pill
    };
})();
