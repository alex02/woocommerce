/**
 * External dependencies
 */
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export const SUPPORT_REPORT_PATH = '/wc/v3/agent-readiness/support-report';

/**
 * Saves the text report a merchant sends to support, built on the server from the last check.
 */
export const SupportDownload = ( {
	onError,
}: {
	onError: ( message: string ) => void;
} ) => {
	const [ busy, setBusy ] = useState( false );

	const download = async () => {
		setBusy( true );
		onError( '' );
		try {
			const { filename, text } = await apiFetch< {
				filename: string;
				text: string;
			} >( { path: SUPPORT_REPORT_PATH } );
			const url = URL.createObjectURL(
				new Blob( [ text ], { type: 'text/plain;charset=utf-8' } )
			);
			const link = document.createElement( 'a' );
			link.href = url;
			link.download = filename;
			link.click();
			URL.revokeObjectURL( url );
		} catch {
			onError(
				__(
					'The report couldn’t be downloaded. Try again.',
					'woocommerce'
				)
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<Button
			variant="secondary"
			isBusy={ busy }
			disabled={ busy }
			onClick={ download }
		>
			{ __( 'Download for support', 'woocommerce' ) }
		</Button>
	);
};
