'use strict';
const CACHE = 'solportal-shell-v4';
const SHELL = ['/', '/assets/css/app.css', '/assets/css/insights.css', '/assets/css/learning.css', '/assets/js/app.js', '/assets/images/growatt-inverter-studio.png', '/manifest.webmanifest'];
self.addEventListener('install', event => event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(SHELL))));
self.addEventListener('activate', event => event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key !== CACHE).map(key => caches.delete(key))))));
self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET' || event.request.url.includes('/api/') || event.request.url.includes('/login') || event.request.url.includes('/admin')) return;
    event.respondWith(fetch(event.request).then(response => {
        const copy = response.clone();
        caches.open(CACHE).then(cache => cache.put(event.request, copy));
        return response;
    }).catch(() => caches.match(event.request)));
});
