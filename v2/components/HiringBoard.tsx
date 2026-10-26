import React from 'react';
import { JOBS, HIRING_STATS } from '../constants';
import type { Job, Stat } from '../types';

const JobCard: React.FC<{ job: Job }> = ({ job }) => (
  <div className="bg-white p-6 rounded-lg border border-gray-200 hover:border-blue-400 hover:shadow-lg transition-all duration-300">
    <div className="flex justify-between items-start">
        <div>
            <h3 className="text-xl font-bold text-gray-900">{job.title}</h3>
            <span className="text-sm font-medium text-blue-800 bg-blue-100 px-2 py-1 rounded mt-1 inline-block">{job.type}</span>
        </div>
        <i className="fas fa-briefcase text-2xl text-blue-400"></i>
    </div>
    <p className="text-gray-600 mt-4">{job.description}</p>
    <a href="#" className="mt-6 inline-block text-blue-600 font-semibold hover:text-blue-700 transition-colors">
      Learn More <i className="fas fa-arrow-right ml-1"></i>
    </a>
  </div>
);

const StatCard: React.FC<{ stat: Stat }> = ({ stat }) => (
    <div className="bg-white p-6 rounded-lg text-center border border-gray-200">
        <i className={`${stat.icon} text-4xl text-blue-500 mb-3`}></i>
        <p className="text-3xl font-bold text-gray-900">{stat.value}</p>
        <p className="text-md text-gray-600">{stat.label}</p>
    </div>
);


const HiringBoard: React.FC = () => {
  return (
    <section id="hiring-board" className="py-20 sm:py-24 bg-gray-50 border-t border-b border-gray-200">
      <div className="container mx-auto px-4 sm:px-6 lg:px-8">
        <div className="lg:grid lg:grid-cols-3 lg:gap-8 items-center">
          <div className="lg:col-span-1">
            <h2 className="text-3xl sm:text-4xl font-bold tracking-tight text-gray-900">Join The Ecosystem.</h2>
            <p className="mt-4 text-lg text-gray-600">
              This is more than a marketplace. It's a career platform for e-commerce specialists, sales agents, and creatives. Find your next role with Product.
            </p>
            <div className="mt-8 grid grid-cols-1 gap-4">
              {HIRING_STATS.map(stat => <StatCard key={stat.label} stat={stat} />)}
            </div>
          </div>
          <div className="mt-12 lg:mt-0 lg:col-span-2">
            <div className="space-y-6">
              {JOBS.map((job) => (
                <JobCard key={job.id} job={job} />
              ))}
            </div>
          </div>
        </div>
      </div>
    </section>
  );
};

export default HiringBoard;