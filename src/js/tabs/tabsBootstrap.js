/**
 * @file js/tabs/tabsBootstrap.js
 * @description Bootstrap sidebar tab modules and expose onclick handlers on window.
 */

import './tabsMemoryEdit.js';
import './tabsQueryCache.js';
import './tabsChatFilter.js';
import { toggleStarSession } from './tabsChatStar.js';
import { setChatFilter } from './tabsChatFilter.js';
import { toggleCustomImapFields } from './tabsEmailAccount.js';

window.toggleStarSession = toggleStarSession;
window.setChatFilter = setChatFilter;
window.toggleCustomImapFields = toggleCustomImapFields;
