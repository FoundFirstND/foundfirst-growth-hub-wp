// Tests for assets/promo.js. Zero dependencies: node --test tests/js/
// Runs the script in a vm context with a tiny fake DOM.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const SOURCE = readFileSync( new URL( '../../assets/promo.js', import.meta.url ), 'utf8' );

class FakeElement {
	constructor( tag, className = '', props = {} ) {
		this.tagName = tag.toUpperCase();
		this.className = className;
		this.children = [];
		this.parentNode = null;
		this.hidden = false;
		Object.assign( this, props );
	}

	append( child ) {
		child.parentNode = this;
		this.children.push( child );
		return child;
	}

	removeChild( child ) {
		this.children = this.children.filter( ( c ) => c !== child );
		child.parentNode = null;
	}

	matches( selector ) {
		const [ tag, cls ] = selector.split( '.' );
		return ( ! tag || tag.toUpperCase() === this.tagName ) && this.className.split( /\s+/ ).includes( cls );
	}

	querySelector( selector ) {
		for ( const child of this.children ) {
			if ( child.matches( selector ) ) {
				return child;
			}
			const found = child.querySelector( selector );
			if ( found ) {
				return found;
			}
		}
		return null;
	}

	querySelectorAll( selector ) {
		const all = [];
		for ( const child of this.children ) {
			if ( child.matches( selector ) ) {
				all.push( child );
			}
			all.push( ...child.querySelectorAll( selector ) );
		}
		return all;
	}
}

function page( { videoProps = {}, withFallback = true, readyState = 'complete' } = {} ) {
	const body = new FakeElement( 'body' );
	const box = body.append( new FakeElement( 'div', 'ffgh-promo__inner' ) );
	const video = box.append( new FakeElement( 'video', 'ffgh-promo__video', { error: null, networkState: 1, ...videoProps } ) );
	const fallback = withFallback ? box.append( new FakeElement( 'a', 'ffgh-promo__video-link ffgh-promo__video-fallback', { hidden: true } ) ) : null;
	box.append( new FakeElement( 'span', 'ffgh-promo__text' ) );

	const listeners = [];
	const document = {
		readyState,
		addEventListener: ( type, fn, capture ) => listeners.push( { type, fn, capture: !! capture } ),
		querySelectorAll: ( selector ) => body.querySelectorAll( selector ),
	};
	vm.runInNewContext( SOURCE, { document } );

	const fire = ( type, target ) => listeners.filter( ( l ) => l.type === type ).forEach( ( l ) => l.fn( { target } ) );

	return { box, video, fallback, listeners, fire };
}

test( 'a video that fails to load is replaced by its thumbnail fallback', () => {
	const { box, video, fallback, listeners, fire } = page();

	assert.ok( listeners.some( ( l ) => 'error' === l.type && l.capture ), 'listens for errors in the capture phase' );
	fire( 'error', video );

	assert.equal( video.parentNode, null );
	assert.equal( box.querySelector( 'video.ffgh-promo__video' ), null );
	assert.equal( fallback.hidden, false );
} );

test( 'a video that already failed before the script ran is swapped on load', () => {
	const { video, fallback } = page( { videoProps: { networkState: 3 } } );

	assert.equal( video.parentNode, null );
	assert.equal( fallback.hidden, false );
} );

test( 'it waits for DOMContentLoaded when the page is still loading', () => {
	const { video, fallback, fire } = page( { videoProps: { error: { code: 4 } }, readyState: 'loading' } );

	assert.notEqual( video.parentNode, null );
	fire( 'DOMContentLoaded' );
	assert.equal( fallback.hidden, false );
} );

test( 'a healthy video and errors from other elements are left alone', () => {
	const { box, video, fallback, fire } = page();

	fire( 'error', new FakeElement( 'img', 'ffgh-promo__image' ) );

	assert.equal( video.parentNode, box );
	assert.equal( fallback.hidden, true );
} );

test( 'without a fallback the video is removed and nothing throws', () => {
	const { box, video, fire } = page( { withFallback: false } );

	assert.doesNotThrow( () => fire( 'error', video ) );
	assert.equal( box.querySelector( 'video.ffgh-promo__video' ), null );
	assert.ok( box.querySelector( 'span.ffgh-promo__text' ) );
} );
