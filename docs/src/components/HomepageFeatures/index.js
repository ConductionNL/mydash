import React from 'react';
import clsx from 'clsx';
import styles from './styles.module.css';

const FeatureList = [
  {
    title: 'Customizable Dashboard',
    description: (
      <>
        Build your perfect Nextcloud dashboard with configurable widgets, layouts, and data sources tailored to your workflow.
      </>
    ),
  },
  {
    title: 'Widget Ecosystem',
    description: (
      <>
        Choose from a growing library of widgets — charts, KPIs, activity feeds, and integrations with other Nextcloud apps.
      </>
    ),
  },
  {
    title: 'Personal & Shared',
    description: (
      <>
        Create personal dashboards or share team views. Each user gets a dashboard that fits their role and responsibilities.
      </>
    ),
  },
];

function Feature({title, description}) {
  return (
    <div className={clsx('col col--4')}>
      <div className="text--center padding-horiz--md">
        <h3>{title}</h3>
        <p>{description}</p>
      </div>
    </div>
  );
}

export default function HomepageFeatures() {
  return (
    <section className={styles.features}>
      <div className="container">
        <div className="row">
          {FeatureList.map((props, idx) => (
            <Feature key={idx} {...props} />
          ))}
        </div>
      </div>
    </section>
  );
}
