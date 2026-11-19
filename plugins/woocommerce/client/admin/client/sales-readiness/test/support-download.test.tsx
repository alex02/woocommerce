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
import { CheckedCard } from '../components/checked-card';
import type { ReadinessView } from '../types';

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
		expect( link.isConnected ).toBe( false );
		const blob = ( URL.createObjectURL as jest.Mock ).mock
			.calls[ 0 ][ 0 ] as Blob;
		expect( blob.type ).toMatch( /^text\/plain/ );
		expect( blob.size ).toBe( 'Site: https://example.org\n'.length );
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

describe( 'CheckedCard', () => {
	it( 'offers Download for support next to Check again, since the report comes from the last check', () => {
		render(
			<CheckedCard
				view={
					{
						last_checked: 'Last checked today',
						checked_note: null,
						intro: null,
						run: {
							label: 'Check again',
							pending: false,
							stalled: false,
							message: '',
							link: null,
						},
					} as unknown as ReadinessView
				}
				running={ false }
				onRun={ jest.fn() }
				onDownloadError={ jest.fn() }
			/>
		);

		const checkAgain = screen.getByRole( 'button', {
			name: 'Check again',
		} );
		const download = screen.getByRole( 'button', {
			name: 'Download for support',
		} );
		expect( download.parentElement ).toBe( checkAgain.parentElement );
	} );
} );
