import type { Metadata } from "next";
import "./globals.css";
import "./refinements.css";
import "./redesign.css";
import "./category-stage.css";
import "./section-filters.css";

export const metadata: Metadata = {
  title: "ELSTAR — свет для вашего дома",
  description: "Салон освещения ELSTAR. Каталог светильников, подбор и монтаж. Шоурум в Красногорске, ТЦ Клён.",
  icons: {
    icon: "/favicon.svg",
    shortcut: "/favicon.svg",
  },
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="ru">
      <body className="antialiased">{children}</body>
    </html>
  );
}
