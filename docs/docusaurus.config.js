// @ts-check

/** @type {import('@docusaurus/types').Config} */
const config = {
  title: 'MyDash',
  tagline: 'Your customizable dashboard for Nextcloud',
  url: 'https://mydash.app',
  baseUrl: '/',

  // GitHub pages deployment config
  organizationName: 'ConductionNL',
  projectName: 'mydash',
  trailingSlash: false,

  onBrokenLinks: 'warn',
  onBrokenMarkdownLinks: 'warn',

  i18n: {
    defaultLocale: 'en',
    locales: ['en'],
  },

  presets: [
    [
      'classic',
      /** @type {import('@docusaurus/preset-classic').Options} */
      ({
        docs: {
          path: './',
          sidebarPath: require.resolve('./sidebars.js'),
          editUrl:
            'https://github.com/ConductionNL/mydash/tree/main/docs/',
        },
        blog: false,
        theme: {
          customCss: require.resolve('./src/css/custom.css'),
        },
      }),
    ],
  ],

  themeConfig:
    /** @type {import('@docusaurus/preset-classic').ThemeConfig} */
    ({
      navbar: {
        title: 'MyDash',
        logo: {
          alt: 'MyDash Logo',
          src: 'img/logo.svg',
        },
        items: [
          {
            type: 'docSidebar',
            sidebarId: 'tutorialSidebar',
            position: 'left',
            label: 'Documentation',
          },
          {
            href: 'https://github.com/ConductionNL/mydash',
            label: 'GitHub',
            position: 'right',
          },
        ],
      },
      footer: {
        style: 'dark',
        links: [
          {
            title: 'Docs',
            items: [
              {
                label: 'Documentation',
                to: '/docs/intro',
              },
            ],
          },
          {
            title: 'Community',
            items: [
              {
                label: 'GitHub',
                href: 'https://github.com/ConductionNL/mydash',
              },
            ],
          },
        ],
        copyright: `Copyright © ${new Date().getFullYear()} for <a href="https://openwebconcept.nl">Open Webconcept</a> by <a href="https://conduction.nl">Conduction B.V.</a>`,
      },
      prism: {
        theme: require('prism-react-renderer/themes/github'),
        darkTheme: require('prism-react-renderer/themes/dracula'),
      },
      mermaid: {
        theme: { light: 'default', dark: 'dark' },
      },
    }),
  markdown: {
    mermaid: true,
  },
  themes: ['@docusaurus/theme-mermaid'],
};

module.exports = config;
