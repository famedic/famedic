var staticCacheName = "pwa-v" + new Date().getTime();
var filesToCache = [
	"/offline",
	"/css/app.css",
	"/js/app.js",
	"images/icons/windows11/SmallTile.scale-100.png",
	"images/icons/windows11/SmallTile.scale-125.png",
	"images/icons/windows11/SmallTile.scale-150.png",
	"images/icons/windows11/SmallTile.scale-200.png",
	"images/icons/windows11/SmallTile.scale-400.png",
	"images/icons/windows11/Square150x150Logo.scale-100.png",
	"images/icons/windows11/Square150x150Logo.scale-125.png",
	"images/icons/windows11/Square150x150Logo.scale-150.png",
	"images/icons/windows11/Square150x150Logo.scale-200.png",
	"images/icons/windows11/Square150x150Logo.scale-400.png",
	"images/icons/windows11/Wide310x150Logo.scale-100.png",
	"images/icons/windows11/Wide310x150Logo.scale-125.png",
	"images/icons/windows11/Wide310x150Logo.scale-150.png",
	"images/icons/windows11/Wide310x150Logo.scale-200.png",
	"images/icons/windows11/Wide310x150Logo.scale-400.png",
	"images/icons/windows11/LargeTile.scale-100.png",
	"images/icons/windows11/LargeTile.scale-125.png",
	"images/icons/windows11/LargeTile.scale-150.png",
	"images/icons/windows11/LargeTile.scale-200.png",
	"images/icons/windows11/LargeTile.scale-400.png",
	"images/icons/windows11/Square44x44Logo.scale-100.png",
	"images/icons/windows11/Square44x44Logo.scale-125.png",
	"images/icons/windows11/Square44x44Logo.scale-150.png",
	"images/icons/windows11/Square44x44Logo.scale-200.png",
	"images/icons/windows11/Square44x44Logo.scale-400.png",
	"images/icons/windows11/StoreLogo.scale-100.png",
	"images/icons/windows11/StoreLogo.scale-125.png",
	"images/icons/windows11/StoreLogo.scale-150.png",
	"images/icons/windows11/StoreLogo.scale-200.png",
	"images/icons/windows11/StoreLogo.scale-400.png",
	"images/icons/windows11/SplashScreen.scale-100.png",
	"images/icons/windows11/SplashScreen.scale-125.png",
	"images/icons/windows11/SplashScreen.scale-150.png",
	"images/icons/windows11/SplashScreen.scale-200.png",
	"images/icons/windows11/SplashScreen.scale-400.png",
	"images/icons/windows11/Square44x44Logo.targetsize-16.png",
	"images/icons/windows11/Square44x44Logo.targetsize-20.png",
	"images/icons/windows11/Square44x44Logo.targetsize-24.png",
	"images/icons/windows11/Square44x44Logo.targetsize-30.png",
	"images/icons/windows11/Square44x44Logo.targetsize-32.png",
	"images/icons/windows11/Square44x44Logo.targetsize-36.png",
	"images/icons/windows11/Square44x44Logo.targetsize-40.png",
	"images/icons/windows11/Square44x44Logo.targetsize-44.png",
	"images/icons/windows11/Square44x44Logo.targetsize-48.png",
	"images/icons/windows11/Square44x44Logo.targetsize-60.png",
	"images/icons/windows11/Square44x44Logo.targetsize-64.png",
	"images/icons/windows11/Square44x44Logo.targetsize-72.png",
	"images/icons/windows11/Square44x44Logo.targetsize-80.png",
	"images/icons/windows11/Square44x44Logo.targetsize-96.png",
	"images/icons/windows11/Square44x44Logo.targetsize-256.png",
	"images/icons/windows11/Square44x44Logo.altform-unplated_targetsize-16.png",
	"images/icons/windows11/Square44x44Logo.altform-unplated_targetsize-20.png",
	"images/icons/windows11/Square44x44Logo.altform-unplated_targetsize-24.png",
	"images/icons/windows11/Square44x44Logo.altform-unplated_targetsize-30.png",
	"images/icons/windows11/Square44x44Logo.altform-unplated_targetsize-32.png",
	"images/icons/windows11/Square44x44Logo.altform-unplated_targetsize-36.png",
	"images/icons/windows11/Square44x44Logo.altform-unplated_targetsize-40.png",
	"images/icons/windows11/Square44x44Logo.altform-unplated_targetsize-44.png",
	"images/icons/windows11/Square44x44Logo.altform-unplated_targetsize-48.png",
	"images/icons/windows11/Square44x44Logo.altform-unplated_targetsize-60.png",
	"images/icons/windows11/Square44x44Logo.altform-unplated_targetsize-64.png",
	"images/icons/windows11/Square44x44Logo.altform-unplated_targetsize-72.png",
	"images/icons/windows11/Square44x44Logo.altform-unplated_targetsize-80.png",
	"images/icons/windows11/Square44x44Logo.altform-unplated_targetsize-96.png",
	"images/icons/windows11/Square44x44Logo.altform-unplated_targetsize-256.png",
	"images/icons/windows11/Square44x44Logo.altform-lightunplated_targetsize-16.png",
	"images/icons/windows11/Square44x44Logo.altform-lightunplated_targetsize-20.png",
	"images/icons/windows11/Square44x44Logo.altform-lightunplated_targetsize-24.png",
	"images/icons/windows11/Square44x44Logo.altform-lightunplated_targetsize-30.png",
	"images/icons/windows11/Square44x44Logo.altform-lightunplated_targetsize-32.png",
	"images/icons/windows11/Square44x44Logo.altform-lightunplated_targetsize-36.png",
	"images/icons/windows11/Square44x44Logo.altform-lightunplated_targetsize-40.png",
	"images/icons/windows11/Square44x44Logo.altform-lightunplated_targetsize-44.png",
	"images/icons/windows11/Square44x44Logo.altform-lightunplated_targetsize-48.png",
	"images/icons/windows11/Square44x44Logo.altform-lightunplated_targetsize-60.png",
	"images/icons/windows11/Square44x44Logo.altform-lightunplated_targetsize-64.png",
	"images/icons/windows11/Square44x44Logo.altform-lightunplated_targetsize-72.png",
	"images/icons/windows11/Square44x44Logo.altform-lightunplated_targetsize-80.png",
	"images/icons/windows11/Square44x44Logo.altform-lightunplated_targetsize-96.png",
	"images/icons/windows11/Square44x44Logo.altform-lightunplated_targetsize-256.png",
	"images/icons/android/android-launchericon-512-512.png",
	"images/icons/android/android-launchericon-192-192.png",
	"images/icons/android/android-launchericon-144-144.png",
	"images/icons/android/android-launchericon-96-96.png",
	"images/icons/android/android-launchericon-72-72.png",
	"images/icons/android/android-launchericon-48-48.png",
	"images/icons/ios/16.png",
	"images/icons/ios/20.png",
	"images/icons/ios/29.png",
	"images/icons/ios/32.png",
	"images/icons/ios/40.png",
	"images/icons/ios/50.png",
	"images/icons/ios/57.png",
	"images/icons/ios/58.png",
	"images/icons/ios/60.png",
	"images/icons/ios/64.png",
	"images/icons/ios/72.png",
	"images/icons/ios/76.png",
	"images/icons/ios/80.png",
	"images/icons/ios/87.png",
	"images/icons/ios/100.png",
	"images/icons/ios/114.png",
	"images/icons/ios/120.png",
	"images/icons/ios/128.png",
	"images/icons/ios/144.png",
	"images/icons/ios/152.png",
	"images/icons/ios/167.png",
	"images/icons/ios/180.png",
	"images/icons/ios/192.png",
	"images/icons/ios/256.png",
	"images/icons/ios/512.png",
	"images/icons/ios/1024.png",
];

// Cache on install
self.addEventListener("install", (installEvent) => {
	installEvent.waitUntil(
		caches.open(staticCacheName).then((cache) => {
			cache.addAll(filesToCache);
		}),
	);
});

// Clear cache on activate
self.addEventListener("activate", (event) => {
	event.waitUntil(
		caches.keys().then((cacheNames) => {
			return Promise.all(
				cacheNames
					.filter((cacheName) => cacheName.startsWith("pwa-"))
					.filter((cacheName) => cacheName !== staticCacheName)
					.map((cacheName) => caches.delete(cacheName)),
			);
		}),
	);
});

// Serve from Cache
self.addEventListener("fetch", (fetchEvent) => {
	fetchEvent.respondWith(
		caches
			.match(fetchEvent.request)
			.then((response) => {
				return response || fetch(fetchEvent.request);
			})
			.catch(() => {
				return caches.match("offline");
			}),
	);
});
