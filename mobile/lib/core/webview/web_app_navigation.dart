import 'package:flc_mobile/config/app_config.dart';

/// Absolute or path URL the embedded WebView should navigate to (FCM / deep link).
/// (Provider lives in app_providers to avoid circular imports.)

/// Map native-style deep links to web paths when they differ.
String mapAppPathToWeb(String path) {
  if (path.startsWith('http://') || path.startsWith('https://')) {
    return path;
  }
  var normalized = path.startsWith('/') ? path : '/$path';
  final uri = Uri.parse('https://flc.local$normalized');
  var p = uri.path;
  if (p.startsWith('/media/')) {
    p = '/home$p';
  }
  final q = uri.hasQuery ? '?${uri.query}' : '';
  return '$p$q';
}

Uri resolveWebAppUri(String pathOrUrl) {
  if (pathOrUrl.startsWith('http://') || pathOrUrl.startsWith('https://')) {
    return Uri.parse(pathOrUrl);
  }
  final mapped = mapAppPathToWeb(pathOrUrl);
  final base = webAppUrl.replaceAll(RegExp(r'/$'), '');
  return Uri.parse('$base$mapped');
}
