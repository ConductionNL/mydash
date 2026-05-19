/**
 * MyDash landing page.
 *
 * Composes the brand <DetailHero> + <WidgetShelf> from
 * @conduction/docusaurus-preset/components, mirroring the connext page
 * at sites/www/src/pages/apps/mydash.mdx.
 *
 * Written as .js (not .mdx) because the docs site has the docs plugin
 * pointed at `path: './'`, and an MDX file in src/pages/ trips the
 * MDX-ESM parser even with the docs plugin's `src/**` exclude — likely
 * a quirk of how mdx-loader's micromark stack reuses parser state
 * across files in this Docusaurus 3.10 + this preset combination.
 * Authoring the page in JSX keeps the same component composition.
 */

import React from 'react';
import Layout from '@theme/Layout';
import {
  DetailHero,
  WidgetShelf,
  AppMock,
} from '@conduction/docusaurus-preset/components';

const MYDASH_ICON = (
  <svg viewBox="0 0 24 24">
    <rect x="3" y="3" width="7" height="9" />
    <rect x="14" y="3" width="7" height="5" />
    <rect x="14" y="12" width="7" height="9" />
    <rect x="3" y="16" width="7" height="5" />
  </svg>
);

const TAGLINE = (
  <>
    Personal and team dashboards, built directly on your{' '}
    <span className="next-blue">Nextcloud</span>. No separate BI tool, no extra
    login, no ETL. Whatever you have in{' '}
    <span className="next-blue">Nextcloud</span>, you can visualise in MyDash.
  </>
);

function BiPanel() {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      <div
        style={{
          display: 'flex',
          alignItems: 'baseline',
          gap: 6,
          marginBottom: 4,
        }}
      >
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 24,
            fontWeight: 700,
            color: 'var(--c-cobalt-700)',
          }}
        >
          1.247
        </div>
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 10,
            letterSpacing: '0.05em',
            color: 'var(--c-mint-500)',
          }}
        >
          +18%
        </div>
      </div>
      <div
        style={{ display: 'flex', alignItems: 'flex-end', gap: 4, height: 50 }}
      >
        {[40, 55, 35, 70, 50, 80, 65].map((h, i) => (
          <div
            key={i}
            style={{
              flex: 1,
              height: `${h}%`,
              background:
                i === 5 ? 'var(--c-mint-500)' : 'var(--c-cobalt-300)',
              borderRadius: 1,
            }}
          />
        ))}
      </div>
    </div>
  );
}

function CalendarPanel() {
  const rows = [
    { date: '14', tone: 'var(--c-mint-500)' },
    { date: '15', tone: 'var(--c-lavender-300)' },
    { date: '17', tone: 'var(--c-cobalt-300)' },
    { date: '21', tone: 'var(--c-forest-300)' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 8 }}
        >
          <div
            style={{
              width: 22,
              height: 25,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              flexShrink: 0,
            }}
          >
            <div
              style={{
                fontFamily: 'var(--conduction-typography-font-family-code)',
                fontSize: 9,
                fontWeight: 700,
                color: 'var(--c-cobalt-700)',
              }}
            >
              {row.date}
            </div>
          </div>
          <div
            style={{
              flex: 1,
              display: 'flex',
              flexDirection: 'column',
              gap: 3,
            }}
          >
            <div
              style={{
                height: 4,
                width: '75%',
                background: 'var(--c-cobalt-700)',
                borderRadius: 1,
              }}
            />
            <div
              style={{
                height: 3,
                width: '55%',
                background: 'var(--c-cobalt-200)',
                borderRadius: 1,
              }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

function WerkvoorraadPanel() {
  const rows = [
    { tone: 'var(--c-orange-knvb)', l1: '60%', l2: '40%', av: 'var(--c-mint-300)' },
    { tone: 'var(--c-lavender-300)', l1: '70%', l2: '50%', av: 'var(--c-lavender-300)' },
    { tone: 'var(--c-red-vermillion)', l1: '55%', l2: '45%', av: 'var(--c-orange-knvb)' },
    { tone: 'var(--c-lavender-300)', l1: '65%', l2: '35%', av: 'var(--c-mint-300)' },
    { tone: 'var(--c-lavender-300)', l1: '75%', l2: '50%', av: 'var(--c-forest-300)' },
  ];
  return (
    <div
      style={{
        display: 'flex',
        flexDirection: 'column',
        gap: 6,
        fontFamily: 'var(--conduction-typography-font-family-body)',
      }}
    >
      {rows.map((row, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 8 }}
        >
          <span
            style={{
              width: 14,
              height: 16,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              display: 'flex',
              flexDirection: 'column',
              gap: 3,
            }}
          >
            <div
              style={{
                height: 5,
                width: row.l1,
                background: 'var(--c-cobalt-700)',
                borderRadius: 1,
              }}
            />
            <div
              style={{
                height: 3,
                width: row.l2,
                background: 'var(--c-cobalt-200)',
                borderRadius: 1,
              }}
            />
          </div>
          <span
            style={{
              width: 18,
              height: 18,
              borderRadius: '50%',
              background: row.av,
              flexShrink: 0,
            }}
          />
        </div>
      ))}
    </div>
  );
}

const WIDGETS = [
  {
    title: 'BI on a register',
    desc: 'Read OpenRegister directly via REST or GraphQL. Drop a chart on the board, configure the field, save.',
    panel: <BiPanel />,
  },
  {
    title: 'Calendar from Nextcloud',
    desc: 'The Nextcloud Calendar dashboard widget lands on MyDash without extra config. Same auth, same source.',
    panel: <CalendarPanel />,
  },
  {
    title: 'Werkvoorraad from Procest',
    desc: 'Any app that registers a Nextcloud dashboard widget shows up here. Procest, DocuDesk, Mail, you name it.',
    panel: <WerkvoorraadPanel />,
  },
];

export default function Home() {
  return (
    <Layout
      title="MyDash, customizable dashboards for Nextcloud workspaces"
      description="Personal and team dashboards built directly on your Nextcloud registers. No separate BI tool, no extra login, no ETL."
    >
      <main className="marketing-page">
        <DetailHero
          appId="mydash"
          background="cobalt"
          status={{ label: 'Beta', color: 'var(--c-orange-knvb)' }}
          version="v0.9"
          locales="NL · EN"
          title="MyDash"
          tagline={TAGLINE}
          primaryCta={{
            label: 'Install from app store',
            href: 'https://apps.nextcloud.com/apps/mydash',
            tone: 'orange',
          }}
          secondaryCta={{ label: 'Read the docs', href: '/docs/intro' }}
          tertiaryCta={{
            label: 'View on GitHub',
            href: 'https://github.com/ConductionNL/mydash',
          }}
          iconColor="var(--c-orange-knvb)"
          icon={MYDASH_ICON}
          illustration={<AppMock app="mydash" />}
        />

        <WidgetShelf
          eyebrow="Widgets we host"
          title="Every Nextcloud app brings its own."
          lede="MyDash is the surface every other app's widget shows up on. Drop these onto a board, drag to size, share with the team. The widget itself comes from the app that ships it."
          widgets={WIDGETS}
        />
      </main>
    </Layout>
  );
}
