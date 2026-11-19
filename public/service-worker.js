const CACHE='demutranweb-static-v3';
const BASE=new URL('./',self.location.href);
const ASSETS=['assets/css/app.css','assets/js/app.js','assets/icons/icon.svg','manifest.webmanifest','offline.html'].map(path=>new URL(path,BASE).href);
const OFFLINE=new URL('offline.html',BASE).href;
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(ASSETS)).then(()=>self.skipWaiting())));
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key!==CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',event=>{
 const request=event.request,url=new URL(request.url);
 if(request.method!=='GET'||url.origin!==self.location.origin)return;
 if(request.mode==='navigate'){
  event.respondWith(fetch(request).catch(()=>caches.match(OFFLINE)));
  return;
 }
 if(ASSETS.includes(request.url))event.respondWith(caches.match(request).then(cached=>cached||fetch(request)));
});
