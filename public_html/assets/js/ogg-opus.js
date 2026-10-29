/*
 * Minimal Ogg Opus muxer. Chrome records voice notes as MP4/WebM, which WhatsApp rejects ("Media upload error");
 * WhatsApp accepts audio/ogg with Opus. The browser's WebCodecs AudioEncoder gives raw Opus packets, this wraps them
 * in an Ogg stream (RFC 3533 pages, RFC 7845 OpusHead / OpusTags headers).
 */
(function (root) {
    'use strict';

    // Ogg CRC-32: polynomial 0x04C11DB7, no reflection, init 0, no final xor.
    var TABLE = (function () {
        var t = new Uint32Array(256);
        for (var i = 0; i < 256; i++) {
            var r = i << 24;
            for (var j = 0; j < 8; j++) r = (r & 0x80000000) ? ((r << 1) ^ 0x04C11DB7) : (r << 1);
            t[i] = r >>> 0;
        }
        return t;
    })();

    function crc32(bytes) {
        var c = 0;
        for (var i = 0; i < bytes.length; i++) c = ((c << 8) ^ TABLE[((c >>> 24) ^ bytes[i]) & 0xFF]) >>> 0;
        return c >>> 0;
    }

    function u32(n) { return [n & 255, (n >>> 8) & 255, (n >>> 16) & 255, (n >>> 24) & 255]; }

    /** One Ogg page from whole packets. granule: samples at 48 kHz up to the end of this page. */
    function page(packets, headerType, granule, serial, seq) {
        var segs = [];
        packets.forEach(function (p) {
            var n = p.length;
            while (n >= 255) { segs.push(255); n -= 255; }
            segs.push(n); // a packet whose length is a multiple of 255 ends with a 0 lacing value
        });
        if (segs.length > 255) throw new Error('too many segments for one page');
        var body = 0;
        packets.forEach(function (p) { body += p.length; });
        var out = new Uint8Array(27 + segs.length + body);
        out.set([0x4F, 0x67, 0x67, 0x53, 0, headerType], 0); // "OggS", version 0, header type
        // granule position, 64-bit little endian (only the low 53 bits are ever needed)
        var lo = granule % 4294967296, hi = Math.floor(granule / 4294967296);
        out.set(u32(lo).concat(u32(hi)), 6);
        out.set(u32(serial), 14);
        out.set(u32(seq), 18);
        // bytes 22..25 = CRC, zero while computing
        out[26] = segs.length;
        out.set(segs, 27);
        var off = 27 + segs.length;
        packets.forEach(function (p) { out.set(p, off); off += p.length; });
        out.set(u32(crc32(out)), 22);
        return out;
    }

    function opusHead(channels, sampleRate, preSkip) {
        var h = new Uint8Array(19);
        h.set([0x4F, 0x70, 0x75, 0x73, 0x48, 0x65, 0x61, 0x64, 1, channels], 0); // "OpusHead", version 1
        h.set([preSkip & 255, (preSkip >> 8) & 255], 10);
        h.set(u32(sampleRate), 12);
        h.set([0, 0, 0], 16); // output gain, mapping family 0
        return h;
    }

    function opusTags() {
        var vendor = 'tourfecto';
        var t = new Uint8Array(8 + 4 + vendor.length + 4);
        t.set([0x4F, 0x70, 0x75, 0x73, 0x54, 0x61, 0x67, 0x73], 0); // "OpusTags"
        t.set(u32(vendor.length), 8);
        for (var i = 0; i < vendor.length; i++) t[12 + i] = vendor.charCodeAt(i);
        // user comment list length = 0 (already zero)
        return t;
    }

    /**
     * @param {Uint8Array[]} packets Opus packets in order
     * @param {number[]} samples     duration of each packet in samples at 48 kHz (960 for 20 ms)
     * @param {{head?:Uint8Array, channels?:number, preSkip?:number}} opts
     * @returns {Uint8Array}
     */
    function mux(packets, samples, opts) {
        opts = opts || {};
        var serial = (Math.random() * 4294967295) >>> 0;
        var head = opts.head && opts.head.length >= 19 ? opts.head : opusHead(opts.channels || 1, 48000, opts.preSkip == null ? 312 : opts.preSkip);
        var pages = [page([head], 0x02, 0, serial, 0), page([opusTags()], 0, 0, serial, 1)];
        var seq = 2, granule = 0, i = 0, PER_PAGE = 50;
        if (!packets.length) throw new Error('no audio');
        while (i < packets.length) {
            var chunk = packets.slice(i, i + PER_PAGE), n = 0;
            // keep a page under 255 lacing segments
            var segCount = 0, take = 0;
            for (var k = 0; k < chunk.length; k++) {
                var need = Math.floor(chunk[k].length / 255) + 1;
                if (segCount + need > 255) break;
                segCount += need; take++;
            }
            chunk = chunk.slice(0, Math.max(1, take));
            for (var m = 0; m < chunk.length; m++) { granule += samples[i + m]; n++; }
            i += n;
            pages.push(page(chunk, i >= packets.length ? 0x04 : 0, granule, serial, seq++));
        }
        var total = 0;
        pages.forEach(function (p) { total += p.length; });
        var out = new Uint8Array(total), o = 0;
        pages.forEach(function (p) { out.set(p, o); o += p.length; });
        return out;
    }

    root.OggOpus = { mux: mux, crc32: crc32 };
    if (typeof module !== 'undefined' && module.exports) module.exports = root.OggOpus;
})(typeof window !== 'undefined' ? window : globalThis);
