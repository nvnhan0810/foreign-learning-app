import 'package:flc_mobile/core/theme/app_theme.dart';
import 'package:flc_mobile/core/theme/theme_mode_provider.dart';
import 'package:flc_mobile/core/providers/app_providers.dart';
import 'package:flc_mobile/core/webview/web_app_navigation.dart';
import 'package:flc_mobile/init_dependencies.dart';
import 'package:flc_mobile/router/app_router.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class FlcApp extends ConsumerStatefulWidget {
  const FlcApp({super.key});

  @override
  ConsumerState<FlcApp> createState() => _FlcAppState();
}

class _FlcAppState extends ConsumerState<FlcApp> with WidgetsBindingObserver {
  GoRouter? _router;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      appFcmTokenRegistrar.registerIfLoggedIn();
    }
  }

  void _wireFcm(GoRouter router) {
    appFcmRedirectCoordinator.start((path) {
      if (!mounted) return;
      final webPath = mapAppPathToWeb(path);
      router.go(
        Uri(path: '/app', queryParameters: {'next': webPath}).toString(),
      );
      ref.read(webAppNavigateProvider.notifier).state = webPath;
    });
  }

  @override
  Widget build(BuildContext context) {
    final router = ref.watch(routerProvider);
    final themePreference = ref.watch(themePreferenceProvider);
    if (_router != router) {
      _router = router;
      WidgetsBinding.instance.addPostFrameCallback((_) => _wireFcm(router));
    }

    return MaterialApp.router(
      title: 'Foreign Learner',
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      themeMode: themePreferenceToMode(themePreference),
      debugShowCheckedModeBanner: false,
      routerConfig: router,
    );
  }
}
