/**
 * Pool Block - Entry Point
 *
 * Bloque Gutenberg para asignación de participantes a estudios longitudinales
 * siguiendo probabilidades configurables.
 *
 * @since 2.5.3
 */

import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';
import './editor.scss';
import './style.scss';

registerBlockType(metadata.name, {
    ...metadata,
    edit: Edit,
    save: Save,
});
