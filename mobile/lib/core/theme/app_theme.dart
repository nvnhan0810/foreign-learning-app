import 'package:flutter/material.dart';

class AppTheme {
  static const primary = Color(0xFF4361EE);
  static const primaryDark = Color(0xFF3A0CA3);
  static const darkPrimary = Color(0xFF6B8AFF);
  static const darkPrimaryDark = Color(0xFF5A6FD6);

  static ThemeData light() => _build(
        brightness: Brightness.light,
        primary: primary,
        secondary: primaryDark,
        scaffold: const Color(0xFFF8F9FA),
        surface: Colors.white,
        appBarBg: Colors.white,
        appBarFg: Colors.black87,
        navBarBg: Colors.white,
      );

  static ThemeData dark() => _build(
        brightness: Brightness.dark,
        primary: darkPrimary,
        secondary: darkPrimaryDark,
        scaffold: const Color(0xFF0F1419),
        surface: const Color(0xFF1A2332),
        appBarBg: const Color(0xFF1A2332),
        appBarFg: const Color(0xFFE8EAED),
        navBarBg: const Color(0xFF1A2332),
      );

  static ThemeData _build({
    required Brightness brightness,
    required Color primary,
    required Color secondary,
    required Color scaffold,
    required Color surface,
    required Color appBarBg,
    required Color appBarFg,
    required Color navBarBg,
  }) {
    final base = ThemeData(
      useMaterial3: true,
      brightness: brightness,
      colorScheme: ColorScheme.fromSeed(
        seedColor: primary,
        brightness: brightness,
        primary: primary,
        secondary: secondary,
        surface: surface,
      ),
      fontFamily: 'Roboto',
    );

    return base.copyWith(
      scaffoldBackgroundColor: scaffold,
      appBarTheme: AppBarTheme(
        centerTitle: true,
        backgroundColor: appBarBg,
        foregroundColor: appBarFg,
        elevation: 0,
        scrolledUnderElevation: 0,
      ),
      cardTheme: CardThemeData(
        elevation: 0,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        color: surface,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
        ),
      ),
      floatingActionButtonTheme: FloatingActionButtonThemeData(
        backgroundColor: primary,
        foregroundColor: brightness == Brightness.dark ? Colors.black : Colors.white,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      ),
      navigationBarTheme: NavigationBarThemeData(
        elevation: 0,
        backgroundColor: navBarBg,
        indicatorColor: primary.withValues(alpha: 0.15),
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          if (states.contains(WidgetState.selected)) {
            return TextStyle(fontWeight: FontWeight.bold, color: primary, fontSize: 12);
          }
          return TextStyle(
            fontWeight: FontWeight.normal,
            color: base.colorScheme.onSurfaceVariant,
            fontSize: 12,
          );
        }),
        iconTheme: WidgetStateProperty.resolveWith((states) {
          if (states.contains(WidgetState.selected)) {
            return IconThemeData(color: primary);
          }
          return IconThemeData(color: base.colorScheme.onSurfaceVariant);
        }),
      ),
    );
  }
}
