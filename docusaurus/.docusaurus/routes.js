import React from 'react';
import ComponentCreator from '@docusaurus/ComponentCreator';

export default [
  {
    path: '/docs',
    component: ComponentCreator('/docs', '215'),
    routes: [
      {
        path: '/docs',
        component: ComponentCreator('/docs', '306'),
        routes: [
          {
            path: '/docs',
            component: ComponentCreator('/docs', '815'),
            routes: [
              {
                path: '/docs/development',
                component: ComponentCreator('/docs/development', '8ff'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/intro',
                component: ComponentCreator('/docs/intro', '784'),
                exact: true,
                sidebar: "tutorialSidebar"
              }
            ]
          }
        ]
      }
    ]
  },
  {
    path: '/',
    component: ComponentCreator('/', '2e1'),
    exact: true
  },
  {
    path: '*',
    component: ComponentCreator('*'),
  },
];
