import { isExtensionContextValid } from '../shared/extension-context';
import { initSelectionUi } from './selection-ui';

/** Chỉ chạy trên trang chính, không inject vào iframe quảng cáo / embed. */
if (window.self === window.top && isExtensionContextValid()) {
  initSelectionUi();
}
