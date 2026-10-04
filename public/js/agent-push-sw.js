/**
 * Service worker for ServiceCrew's Agent admin push alerts (Phase D).
 * Served at the root-relative /sc-agent-sw.js by
 * class-service-crew-agent-push.php's maybe_serve_service_worker() — not a
 * static file under this plugin's own directory, so its default
 * registration scope covers the whole site.
 *
 * Deliberately empty-payload: every push just means "something needs
 * attention," never any event-specific detail (that would need RFC8291
 * payload encryption, which this feature's design explicitly skips) — the
 * admin reads the actual detail from the Agent screen itself once they
 * click through.
 */

self.addEventListener( 'push', function ( event ) {
	event.waitUntil(
		self.registration.showNotification( 'ServiceCrew', {
			body: 'A chat visitor needs a human answer — open the Agent screen to see what\'s waiting.',
			tag: 'sc-agent-alert',
			renotify: true,
		} )
	);
} );

self.addEventListener( 'notificationclick', function ( event ) {
	event.notification.close();
	event.waitUntil( self.clients.openWindow( '/wp-admin/admin.php?page=service-crew-agent' ) );
} );
