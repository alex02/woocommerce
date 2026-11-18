/**
 * External dependencies
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import {
	SUPPORT_REPORT_PATH,
	SupportDownload,
} from '../components/support-download';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
const mockFetch = apiFetch as unknown as jest.Mock;

describe( 'SupportDownload', () => {
	let click: jest.SpyInstance;

	beforeEach( () => {
		mockFetch.mockReset();
		URL.createObjectURL = jest.fn( () => 'blob:report' );
		URL.revokeObjectURL = jest.fn();
		click = jest
			.spyOn( window.HTMLAnchorElement.prototype, 'click' )
			.mockImplementation( () => undefined );
	} );
	afterEach( () => click.mockRestore() );

	it( 'saves the report from the support route under its file name', async () => {
		mockFetch.mockResolvedValueOnce( {
			filename: 'ai-sales-readiness-example.org-2026-11-18.txt',
			text: 'Site: https://example.org\n',
		} );
		const onError = jest.fn();
		render( <SupportDownload onError={ onError } /> );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Download for support' } )
		);

		await waitFor( () => expect( click ).toHaveBeenCalledTimes( 1 ) );
		expect( mockFetch ).toHaveBeenCalledWith( {
			path: SUPPORT_REPORT_PATH,
		} );
		const link = click.mock.instances[ 0 ] as unknown as {
			download: string;
		};
		expect( link.download ).toBe(
			'ai-sales-readiness-example.org-2026-11-18.txt'
		);
		expect( URL.revokeObjectURL ).toHaveBeenCalledWith( 'blob:report' );
		expect( onError ).toHaveBeenLastCalledWith( '' );
	} );

	it( 'reports an error when the report can’t be loaded, and saves nothing', async () => {
		mockFetch.mockRejectedValueOnce( new Error( 'Forbidden' ) );
		const onError = jest.fn();
		render( <SupportDownload onError={ onError } /> );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Download for support' } )
		);

		await waitFor( () =>
			expect( onError ).toHaveBeenLastCalledWith(
				'The report couldn’t be downloaded. Try again.'
			)
		);
		expect( click ).not.toHaveBeenCalled();
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Download for support' } )
			).toBeEnabled()
		);
	} );
} );
